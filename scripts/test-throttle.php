<?php
// scripts/test-throttle.php — assertions for login throttling and the IP allowlist.
//
//   scripts/php scripts/test-throttle.php
//
// Run by scripts/smoke.sh.

require_once __DIR__ . '/../web/lib/bootstrap.php';

$passed = 0;
$failed = 0;

function check(string $label, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        printf("  PASS %s\n", $label);
        return;
    }
    $failed++;
    printf("  FAIL %s\n       expected: %s\n       actual:   %s\n",
        $label, var_export($expected, true), var_export($actual, true));
}

// An in-memory database, so the test never touches a real deployment.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE auth_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL, ip TEXT NOT NULL, username TEXT, attempted_at INTEGER NOT NULL)");

$config = ['security' => [
    'login_window'       => 900,
    'login_max_per_ip'   => 10,
    'login_max_per_user' => 5,
]];
$t = new LibreThrottle($pdo, $config);

echo "Login throttling\n";

check('a fresh address may attempt', $t->retryAfter(LibreThrottle::LOGIN, '1.2.3.4', 'alice'), null);

// Per-account limit: 5 failures for one name, spread over different addresses.
for ($i = 0; $i < 4; $i++) {
    $t->recordFailure(LibreThrottle::LOGIN, "10.0.0.$i", 'alice');
}
check('4 failures for one account still allowed',
    $t->retryAfter(LibreThrottle::LOGIN, '10.0.0.9', 'alice'), null);

$t->recordFailure(LibreThrottle::LOGIN, '10.0.0.4', 'alice');
$wait = $t->retryAfter(LibreThrottle::LOGIN, '10.0.0.9', 'alice');
check('5th failure locks the account regardless of source address', is_int($wait) && $wait > 0, true);
check('the lockout does not exceed the window', $wait <= 900, true);

// A different account from an unrelated address is unaffected.
check('another account is unaffected',
    $t->retryAfter(LibreThrottle::LOGIN, '10.0.0.9', 'bob'), null);

// Per-address limit: one address spraying many usernames never trips the per-account
// counter, so the per-address counter has to catch it.
for ($i = 0; $i < 9; $i++) {
    $t->recordFailure(LibreThrottle::LOGIN, '203.0.113.7', "user$i");
}
check('9 failures from one address still allowed',
    $t->retryAfter(LibreThrottle::LOGIN, '203.0.113.7', 'user99'), null);
$t->recordFailure(LibreThrottle::LOGIN, '203.0.113.7', 'user9');
check('10th failure locks the address even with all-different usernames',
    is_int($t->retryAfter(LibreThrottle::LOGIN, '203.0.113.7', 'user99')), true);

// Success clears the counters that were measuring it.
$t->recordSuccess(LibreThrottle::LOGIN, '10.0.0.9', 'alice');
check('a correct login clears the account lockout',
    $t->retryAfter(LibreThrottle::LOGIN, '10.0.0.9', 'alice'), null);

// Attempts outside the window stop counting.
$pdo->exec("DELETE FROM auth_attempts");
$old = time() - 1000;
for ($i = 0; $i < 8; $i++) {
    $pdo->prepare("INSERT INTO auth_attempts (kind, ip, username, attempted_at) VALUES (?,?,?,?)")
        ->execute([LibreThrottle::LOGIN, '198.51.100.1', 'carol', $old]);
}
check('attempts older than the window do not count',
    $t->retryAfter(LibreThrottle::LOGIN, '198.51.100.1', 'carol'), null);

// Setup shares the mechanism but not the counters.
$pdo->exec("DELETE FROM auth_attempts");
for ($i = 0; $i < 10; $i++) {
    $t->recordFailure(LibreThrottle::SETUP, '192.0.2.5');
}
check('setup attempts are throttled',
    is_int($t->retryAfter(LibreThrottle::SETUP, '192.0.2.5')), true);
check('setup failures do not lock out login',
    $t->retryAfter(LibreThrottle::LOGIN, '192.0.2.5', 'dave'), null);

check('wait is described in seconds under a minute', LibreThrottle::describeWait(30), '30 seconds');
check('wait is described in minutes above one', LibreThrottle::describeWait(240), '4 minutes');

echo "\nAddress matching\n";

check('exact v4 match', LibreApp::ipMatches('10.20.28.110', '10.20.28.110'), true);
check('exact v4 mismatch', LibreApp::ipMatches('10.20.28.111', '10.20.28.110'), false);
check('v4 inside /22', LibreApp::ipMatches('10.20.29.5', '10.20.28.0/22'), true);
check('v4 outside /22', LibreApp::ipMatches('10.20.32.5', '10.20.28.0/22'), false);
check('v4 /32', LibreApp::ipMatches('203.0.113.7', '203.0.113.7/32'), true);
check('v4 /0 matches anything', LibreApp::ipMatches('8.8.8.8', '0.0.0.0/0'), true);
check('v6 exact', LibreApp::ipMatches('2001:db8::1', '2001:db8::1'), true);
check('v6 inside /32', LibreApp::ipMatches('2001:db8:1234::1', '2001:db8::/32'), true);
check('v6 outside /32', LibreApp::ipMatches('2001:dead::1', '2001:db8::/32'), false);
check('v4 is not matched by a v6 range', LibreApp::ipMatches('10.0.0.1', '2001:db8::/32'), false);
check('garbage range does not match', LibreApp::ipMatches('10.0.0.1', 'not-a-range'), false);
check('empty range does not match', LibreApp::ipMatches('10.0.0.1', ''), false);

echo "\nForwarded-for handling\n";

$_SERVER['REMOTE_ADDR'] = '10.20.28.1';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';

check('forwarded-for ignored when no proxy is trusted',
    LibreApp::clientIp(['security' => []]), '10.20.28.1');
check('forwarded-for ignored when the peer is not a trusted proxy',
    LibreApp::clientIp(['security' => ['trusted_proxies' => ['192.0.2.0/24']]]), '10.20.28.1');
check('forwarded-for honoured from a trusted proxy',
    LibreApp::clientIp(['security' => ['trusted_proxies' => ['10.20.28.0/22']]]), '1.2.3.4');

// A client prepending entries must not be able to choose its own address.
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'evil, 1.2.3.4';
check('only the right-most forwarded entry is believed',
    LibreApp::clientIp(['security' => ['trusted_proxies' => ['10.20.28.0/22']]]), '1.2.3.4');

$_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
check('an unparseable forwarded-for falls back to the peer',
    LibreApp::clientIp(['security' => ['trusted_proxies' => ['10.20.28.0/22']]]), '10.20.28.1');

printf("\n  %d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
