<?php
// web/lib/throttle.php — failed-authentication throttling.
//
// The dashboard is reachable from wherever it is deployed, and password_verify() on its
// own puts no ceiling on guess rate. Failures are recorded and counted in a sliding
// window, per source address and per account name.
//
// Lockout is by rejection, never by sleeping. Delaying the response would hold a PHP-FPM
// worker open for the duration, which turns a brute-force attempt into a way to exhaust
// the pool.
//
// Counting per account as well as per address matters because a distributed attempt
// spreads across addresses but still converges on one username. Counting per address as
// well as per account matters because spraying one password across many usernames never
// trips a per-account counter.

class LibreThrottle
{
    public const LOGIN = 'login';
    public const SETUP = 'setup';

    private PDO $pdo;
    private int $window;
    private int $maxPerIp;
    private int $maxPerUser;

    public function __construct(PDO $pdo, array $config)
    {
        $security = $config['security'] ?? [];
        $this->pdo = $pdo;
        $this->window = max(60, (int)($security['login_window'] ?? 900));
        $this->maxPerIp = max(1, (int)($security['login_max_per_ip'] ?? 10));
        $this->maxPerUser = max(1, (int)($security['login_max_per_user'] ?? 5));
    }

    /**
     * Seconds the caller must wait, or null when the attempt may proceed.
     */
    public function retryAfter(string $kind, string $ip, ?string $username = null): ?int
    {
        $since = time() - $this->window;
        $waits = [];

        $byIp = $this->oldestAndCount($kind, 'ip', $ip, $since);
        if ($byIp['count'] >= $this->maxPerIp) {
            $waits[] = $byIp['oldest'] + $this->window - time();
        }

        if ($username !== null && $username !== '') {
            $byUser = $this->oldestAndCount($kind, 'username', $username, $since);
            if ($byUser['count'] >= $this->maxPerUser) {
                $waits[] = $byUser['oldest'] + $this->window - time();
            }
        }

        if ($waits === []) {
            return null;
        }

        return max(1, max($waits));
    }

    public function recordFailure(string $kind, string $ip, ?string $username = null): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO auth_attempts (kind, ip, username, attempted_at) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$kind, $ip, $username !== '' ? $username : null, time()]);
        $this->prune();
    }

    /**
     * A correct credential clears the counters it was being measured against, so a user
     * who eventually remembers their password is not left locked out by their own typos.
     */
    public function recordSuccess(string $kind, string $ip, ?string $username = null): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM auth_attempts WHERE kind = ? AND ip = ?");
        $stmt->execute([$kind, $ip]);

        if ($username !== null && $username !== '') {
            $stmt = $this->pdo->prepare("DELETE FROM auth_attempts WHERE kind = ? AND username = ?");
            $stmt->execute([$kind, $username]);
        }
    }

    /** Attempts older than the window can never affect a decision again. */
    public function prune(): void
    {
        $stmt = $this->pdo->prepare("DELETE FROM auth_attempts WHERE attempted_at < ?");
        $stmt->execute([time() - $this->window]);
    }

    /**
     * @return array{count: int, oldest: int}
     */
    private function oldestAndCount(string $kind, string $column, string $value, int $since): array
    {
        // $column is chosen by this class, never by the caller.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS c, COALESCE(MIN(attempted_at), 0) AS o
             FROM auth_attempts
             WHERE kind = ? AND $column = ? AND attempted_at >= ?"
        );
        $stmt->execute([$kind, $value, $since]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['c' => 0, 'o' => 0];

        return ['count' => (int)$row['c'], 'oldest' => (int)$row['o']];
    }

    /**
     * Human-readable delay for the lockout message.
     */
    public static function describeWait(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' second' . ($seconds === 1 ? '' : 's');
        }
        $minutes = (int)ceil($seconds / 60);
        return $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    }
}
