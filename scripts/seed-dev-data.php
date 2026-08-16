<?php
// scripts/seed-dev-data.php — idempotent local fixtures for development and smoke testing.
//
//   scripts/php scripts/seed-dev-data.php
//
// Creates an admin, a standard user with a fixed access token, a personal calendar,
// and a database-backed room. All fixtures use the bundled demo feed, so nothing here
// depends on the network. Never run this against a real deployment.

require_once __DIR__ . '/../web/lib/bootstrap.php';

[$config, $db] = LibreApp::boot();
$pdo = $db->getPdo();

const DEV_TOKEN = 'devtoken00000000000000000000beef';

function upsertUser(PDO $pdo, string $username, string $password, int $isAdmin, ?string $token, array $prefs = []): int
{
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $id = $stmt->fetchColumn();

    if ($id === false) {
        $stmt = $pdo->prepare(
            "INSERT INTO users (username, password_hash, access_token, is_admin) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $token, $isAdmin]);
        $id = (int)$pdo->lastInsertId();
        printf("  created user %s (id %d)\n", $username, $id);
    } else {
        $id = (int)$id;
        printf("  user %s already exists (id %d)\n", $username, $id);
    }

    if ($prefs) {
        $columns = implode(', ', array_map(static fn($c) => "$c = ?", array_keys($prefs)));
        $stmt = $pdo->prepare("UPDATE users SET $columns WHERE id = ?");
        $stmt->execute([...array_values($prefs), $id]);
    }

    return $id;
}

echo "Seeding development fixtures\n";

upsertUser($pdo, 'devadmin', 'devpassword', 1, 'devadmintoken000000000000000beef');

$userId = upsertUser($pdo, 'devuser', 'devpassword', 0, DEV_TOKEN, [
    'view'           => 'dashboard',
    'display_name'   => 'Dev User',
    'time_format'    => 'auto',
    'timezone'       => 'America/Toronto',
    'weather_lat'    => 45.42,
    'weather_lon'    => -75.70,
    'weather_city'   => 'Ottawa',
    'past_horizon'   => 7,
    'future_horizon' => 14,
]);

// The feed list is stored encrypted, so it has to be written through LibreDb.
$stmt = $pdo->prepare("SELECT COUNT(*) FROM calendars WHERE user_id = ?");
$stmt->execute([$userId]);
if ((int)$stmt->fetchColumn() === 0) {
    $stmt = $pdo->prepare("INSERT INTO calendars (user_id, encrypted_url) VALUES (?, ?)");
    $stmt->execute([$userId, $db->encrypt('demo.ics.php')]);
    echo "  created personal calendar (demo.ics.php)\n";
} else {
    echo "  personal calendar already exists\n";
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_key = ?");
$stmt->execute(['devroom']);
if ((int)$stmt->fetchColumn() === 0) {
    $stmt = $pdo->prepare(
        "INSERT INTO rooms (room_key, name, calendar_url, view, time_format, timezone,
                            show_rss, show_weather, weather_lat, weather_lon, weather_city,
                            past_horizon, future_horizon)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        'devroom', 'Dev Boardroom', json_encode(['demo.ics.php']), 'room', '24h',
        'America/Vancouver', 1, 1, 49.28, -123.12, 'Vancouver', 30, 30,
    ]);
    echo "  created room devroom\n";
} else {
    echo "  room devroom already exists\n";
}

printf("\nAdmin login:  devadmin / devpassword\n");
printf("User login:   devuser / devpassword\n");
printf("Token view:   /?userid=%s\n", DEV_TOKEN);
printf("Room view:    /?room=devroom\n");
