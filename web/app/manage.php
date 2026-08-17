<?php
// web/manage.php — Optimized User and Room management dashboard

// SameSite alone blocks most cross-site form posts; the token below covers the rest.
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
]);
session_start();
require_once __DIR__ . "/../lib/bootstrap.php";
[$config, $db] = LibreApp::boot();
$isFallbackConfig = LibreApp::$usingFallbackConfig;

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

function csrfValid(): bool
{
    return !empty($_SESSION['csrf_token'])
        && isset($_POST['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/** Escape for HTML output. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Prevent caching of the management dashboard
LibreApp::noCacheHeaders();

// This page holds credentials and feed URLs, so keep it out of frames and stop the
// browser guessing content types.
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");
header("Content-Security-Policy: frame-ancestors 'none'");

$pdo = $db->getPdo();

$clientIp = LibreApp::clientIp($config);
$throttle = new LibreThrottle($pdo, $config);

// Optional allowlist. Checked before anything else on the page, including the login
// form, so a restricted deployment presents no authentication surface at all.
$allowedIps = $config['security']['manage_allow_ips'] ?? [];
if (!empty($allowedIps)) {
    $permitted = false;
    foreach ((array)$allowedIps as $range) {
        if (LibreApp::ipMatches($clientIp, (string)$range)) {
            $permitted = true;
            break;
        }
    }
    if (!$permitted) {
        http_response_code(403);
        header("Content-Type: text/plain; charset=utf-8");
        echo "Forbidden.\n";
        exit;
    }
}

$error = "";
$message = "";
$tab = $_GET['tab'] ?? 'users';

function clearAllCaches() {
    $files = glob(LibreApp::cacheDir() . '/*.{ics,xml,json}', GLOB_BRACE);
    foreach ($files as $file) {
        if (is_file($file)) unlink($file);
    }
}

// State for editing
$editRoom = null;
if (isset($_GET['edit_room'])) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$_GET['edit_room']]);
    $editRoom = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Check if any admin exists
$stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1");
$adminExists = $stmt->fetchColumn() > 0;

// Dynamically detect base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$dir = dirname($_SERVER['PHP_SELF']);
if ($dir === '/' || $dir === '\\') $dir = '';
$baseUrl = "$protocol://$host$dir/";

// SECURITY CHECK: Verify that /data/cache/ is protected
$securityWarning = "";
if ($adminExists && isset($_SESSION['user_id'])) {
    $dataDir = LibreApp::cacheDir() . '/';
    $cacheFiles = glob($dataDir . '*.{ics,xml,json}', GLOB_BRACE);

    // If no cache exists, trigger RSS to generate one
    if (empty($cacheFiles)) {
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        @file_get_contents($baseUrl . 'rss.php?lang=en', false, $ctx);
        $cacheFiles = glob($dataDir . '*.{ics,xml,json}', GLOB_BRACE);
    }

    if (!empty($cacheFiles)) {
        // Pick the newest file
        usort($cacheFiles, function($a, $b) { return filemtime($b) - filemtime($a); });
        $targetFile = basename($cacheFiles[0]);
        $testUrl = $baseUrl . "../data/cache/" . $targetFile;
        
        $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => true]]);
        $testContent = @file_get_contents($testUrl, false, $ctx);
        $statusLine = $http_response_header[0] ?? '';

        // If we get a 200 OK and content, it's a leak
        if (strpos($statusLine, '200') !== false && strlen($testContent) > 0) {
            $securityWarning = "CRITICAL: The 'data/cache' directory is publicly accessible! .htaccess is not working. File exposed: $targetFile";
        }
    }
}

// Every state change arrives by POST and must carry the session's token. Emptying the
// request rather than branching means a forged post cannot reach any handler below.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrfValid()) {
    $_POST = [];
    $error = "Your session expired or the request could not be verified. Please try again.";
}

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: manage.php");
    exit;
}

// SETUP FLOW: First time admin creation
if (!$adminExists) {
    if (isset($_POST['setup'])) {
        // The setup password creates an administrator, so it is throttled exactly like
        // a login.
        $wait = $throttle->retryAfter(LibreThrottle::SETUP, $clientIp);
        if ($wait !== null) {
            $error = "Too many attempts. Try again in " . LibreThrottle::describeWait($wait) . ".";
        } elseif (hash_equals((string)$config['security']['setup_password'], (string)($_POST['setup_password'] ?? ''))) {
            $token = bin2hex(random_bytes(16));
            $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, access_token, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute([$_POST['username'], $hash, $token]);
            $throttle->recordSuccess(LibreThrottle::SETUP, $clientIp);
            session_regenerate_id(true); // Do not carry a pre-login session id forward.
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['is_admin'] = true;
            $message = "Setup complete! Admin account created.";
            $adminExists = true;
        } else {
            $throttle->recordFailure(LibreThrottle::SETUP, $clientIp);
            $error = "Incorrect Setup Password.";
        }
    }
}
// LOGIN FLOW
else if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['login'])) {
        $username = (string)($_POST['username'] ?? '');
        $wait = $throttle->retryAfter(LibreThrottle::LOGIN, $clientIp, $username);

        if ($wait !== null) {
            // Reject without verifying, and without sleeping: a delayed response would
            // pin a PHP-FPM worker for the duration.
            $error = "Too many failed attempts. Try again in " . LibreThrottle::describeWait($wait) . ".";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify((string)($_POST['password'] ?? ''), $user['password_hash'])) {
                $throttle->recordSuccess(LibreThrottle::LOGIN, $clientIp, $username);
                session_regenerate_id(true); // Do not carry a pre-login session id forward.
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['is_admin'] = (bool)$user['is_admin'];
            } else {
                if (!$user) {
                    // Hash something anyway. Skipping the verify for an unknown username
                    // returns measurably faster and so discloses which names exist.
                    password_verify(
                        (string)($_POST['password'] ?? ''),
                        '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG'
                    );
                }
                $throttle->recordFailure(LibreThrottle::LOGIN, $clientIp, $username);
                $error = "Invalid username or password.";
            }
        }
    }
}

// ACCESS CHECK
if (!$adminExists) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>LibreJoanne Setup</title><style>body{font-family:sans-serif; display:flex; justify-content:center; align-items:center; height:100vh; background:#f0f0f0; margin:0;} form{background:#fff; padding:2rem; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); width:320px;} input{width:100%; padding:10px; margin:10px 0; box-sizing:border-box; border:1px solid #ddd; border-radius:6px;}</style></head>
    <body>
        <form method="POST">
            <h2 style="margin-top:0;">Initial Setup</h2>
            <?php if($isFallbackConfig): ?>
                <div style="background:#fff3cd; color:#856404; padding:10px; border-radius:6px; margin-bottom:1rem; border:1px solid #ffeeba; font-size:0.85rem;">
                    Using <code>config.sample.php</code> defaults.
                </div>
            <?php endif; ?>
            <p style="color:#666; font-size:0.9rem;">Enter the setup password from config.php to create your admin account.</p>
            <?php if($error) echo "<p style='color:red'>" . e($error) . "</p>"; ?>
            <?= csrfField() ?>
            <input type="password" name="setup_password" placeholder="Setup Password" required>
            <hr style="border:0; border-top:1px solid #eee; margin:20px 0;">
            <input type="text" name="username" placeholder="New Admin Username" required>
            <input type="password" name="new_password" placeholder="New Admin Password" required>
            <button type="submit" name="setup" style="width:100%; padding:12px; background:#000; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">Complete Setup</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

if (!isset($_SESSION['user_id'])) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>LibreJoanne Login</title><style>body{font-family:sans-serif; display:flex; justify-content:center; align-items:center; height:100vh; background:#f0f0f0; margin:0;} form{background:#fff; padding:2rem; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.08); width:320px;} input{width:100%; padding:10px; margin:10px 0; box-sizing:border-box; border:1px solid #ddd; border-radius:6px;}</style></head>
    <body>
        <form method="POST">
            <h2 style="margin-top:0;">Login</h2>
            <?php if($isFallbackConfig): ?>
                <div style="background:#fff3cd; color:#856404; padding:10px; border-radius:6px; margin-bottom:1rem; border:1px solid #ffeeba; font-size:0.85rem;">
                    Using <code>config.sample.php</code> defaults.
                </div>
            <?php endif; ?>
            <?php if($error) echo "<p style='color:red'>" . e($error) . "</p>"; ?>
            <?= csrfField() ?>
            <input type="text" name="username" placeholder="Username" required autofocus>
            <input type="password" name="password" placeholder="Password" required>
            <button type="submit" name="login" style="width:100%; padding:12px; background:#000; color:#fff; border:none; border-radius:6px; cursor:pointer; font-weight:bold;">Login</button>
        </form>
    </body>
    </html>
    <?php
    exit;
}

// Admin / User Actions
if (isset($_POST['add_user']) && $_SESSION['is_admin']) {
    $token = bin2hex(random_bytes(16));
    $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
    
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, access_token, is_admin) VALUES (?, ?, ?, ?)");
    try {
        $stmt->execute([$_POST['username'], $hash, $token, $isAdmin]);
        $message = "User created! Access token: $token";
    } catch (Exception $e) { $error = "Username already exists."; }
}

if (isset($_POST['save_user_view'])) {
    if (!$_SESSION['is_admin'] && $_SESSION['user_id'] != $_POST['user_id']) {
        $error = "Unauthorized action.";
    } else {
        // Empty means "follow the site default"; anything else must be a known language.
        $lang = (string)($_POST['lang'] ?? '');
        if (!in_array($lang, LibreContext::LANGUAGES, true)) {
            $lang = '';
        }

        $stmt = $pdo->prepare("UPDATE users SET view = ?, time_format = ?, timezone = ?, lang = ?, show_clock = ?, weather_lat = ?, weather_lon = ?, weather_city = ?, display_name = ?, past_horizon = ?, future_horizon = ? WHERE id = ?");
        $stmt->execute([$_POST['view'], $_POST['time_format'], $_POST['timezone'], $lang, isset($_POST['show_clock']) ? 1 : 0, $_POST['weather_lat'], $_POST['weather_lon'], $_POST['weather_city'], $_POST['display_name'], $_POST['past_horizon'], $_POST['future_horizon'], $_POST['user_id']]);
        clearAllCaches();
        $message = "User preferences updated and caches cleared.";
    }
}

if (isset($_POST['save_user_security'])) {
    if ($_SESSION['is_admin'] || $_SESSION['user_id'] == $_POST['user_id']) {
        $updates = [];
        $params = [];
        
        if (!empty($_POST['new_password'])) {
            $updates[] = "password_hash = ?";
            $params[] = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
        }
        
        if ($_SESSION['is_admin'] && $_SESSION['user_id'] != $_POST['user_id']) {
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $updates[] = "is_admin = ?";
            $params[] = $isAdmin;
        }

        if (!empty($updates)) {
            $params[] = $_POST['user_id'];
            $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $message = "User security settings updated.";
        }
    }
}

if (isset($_POST['save_cal'])) {
    // Auth Check
    $target_user_id = $_POST['user_id'];
    if (!empty($_POST['cal_id'])) {
         $stmt = $pdo->prepare("SELECT user_id FROM calendars WHERE id = ?");
         $stmt->execute([$_POST['cal_id']]);
         $cal = $stmt->fetch();
         if ($cal) $target_user_id = $cal['user_id'];
    }
    
    if (!$_SESSION['is_admin'] && $_SESSION['user_id'] != $target_user_id) {
        $error = "Unauthorized action.";
    } else {
        $encrypted = $db->encrypt($_POST['url']);
        if (!empty($_POST['cal_id'])) {
            $stmt = $pdo->prepare("UPDATE calendars SET encrypted_url = ? WHERE id = ?");
            $stmt->execute([$encrypted, $_POST['cal_id']]);
            $message = "Calendar updated.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO calendars (user_id, encrypted_url) VALUES (?, ?)");
            $stmt->execute([$_POST['user_id'], $encrypted]);
            $message = "Calendar added.";
        }
        clearAllCaches();
    }
}

if (isset($_POST['delete_cal'])) {
    $stmt = $pdo->prepare("SELECT user_id FROM calendars WHERE id = ?");
    $stmt->execute([$_POST['delete_cal']]);
    $cal = $stmt->fetch();

    if ($cal && ($_SESSION['is_admin'] || $_SESSION['user_id'] == $cal['user_id'])) {
        $stmt = $pdo->prepare("DELETE FROM calendars WHERE id = ?");
        $stmt->execute([$_POST['delete_cal']]);
        clearAllCaches();
        $message = "Calendar deleted.";
    } else {
        $error = "Unauthorized action.";
    }
}

// Room Management (Admin Only)
if ($_SESSION['is_admin']) {
    if (isset($_POST['save_room'])) {
        $reservedKeys = ['default', 'personal', 'personal-grid'];
        $roomKey = strtolower(trim($_POST['room_key']));

        if (in_array($roomKey, $reservedKeys)) {
            $error = "The room key '$roomKey' is reserved and cannot be used.";
        } elseif (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $roomKey)) {
            // The key is a URL slug and is echoed back into the edit form, so keep it
            // to characters that are safe in both places.
            $error = "Room keys may contain only lowercase letters, numbers, hyphens and underscores.";
        } else {
            // Empty means "follow the site default"; anything else must be a known language.
            $roomLang = (string)($_POST['lang'] ?? '');
            if (!in_array($roomLang, LibreContext::LANGUAGES, true)) {
                $roomLang = '';
            }

            $urls = array_filter(array_map('trim', explode("\n", $_POST['calendar_urls'])));
            if (!empty($_POST['room_id'])) {
                $stmt = $pdo->prepare("UPDATE rooms SET room_key=?, name=?, calendar_url=?, view=?, time_format=?, timezone=?, lang=?, show_clock=?, show_rss=?, show_weather=?, weather_lat=?, weather_lon=?, weather_city=?, past_horizon=?, future_horizon=? WHERE id=?");
                $stmt->execute([
                    $roomKey, $_POST['name'], json_encode($urls), $_POST['view'], $_POST['time_format'], $_POST['timezone'], $roomLang,
                    isset($_POST['show_clock']) ? 1 : 0, isset($_POST['show_rss']) ? 1 : 0, isset($_POST['show_weather']) ? 1 : 0,
                    $_POST['weather_lat'], $_POST['weather_lon'], $_POST['weather_city'], 
                    $_POST['past_horizon'], $_POST['future_horizon'], $_POST['room_id']
                ]);
                $message = "Room updated!";
            } else {
                $stmt = $pdo->prepare("INSERT INTO rooms (room_key, name, calendar_url, view, time_format, timezone, lang, show_clock, show_rss, show_weather, weather_lat, weather_lon, weather_city, past_horizon, future_horizon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                try {
                    $stmt->execute([
                        $roomKey, $_POST['name'], json_encode($urls), $_POST['view'], $_POST['time_format'], $_POST['timezone'], $roomLang,
                        isset($_POST['show_clock']) ? 1 : 0, isset($_POST['show_rss']) ? 1 : 0, isset($_POST['show_weather']) ? 1 : 0,
                        $_POST['weather_lat'], $_POST['weather_lon'], $_POST['weather_city'],
                        $_POST['past_horizon'], $_POST['future_horizon']
                    ]);
                    $message = "Room created!";
                } catch (Exception $e) { $error = "Room key already exists."; }
            }
            clearAllCaches();
            $editRoom = null;
        }
    }

    if (isset($_POST['delete_room'])) {
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([$_POST['delete_room']]);
        clearAllCaches();
        $message = "Room deleted.";
    }

    if (isset($_POST['clear_cache'])) {
        clearAllCaches();
        $message = "All caches cleared manually.";
    }
}

$users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$rooms = $pdo->query("SELECT * FROM rooms")->fetchAll(PDO::FETCH_ASSOC);

// Generate Timezone list for autocomplete
$allTimezones = DateTimeZone::listIdentifiers();
?>

<!DOCTYPE html>
<html>
<head>
    <title>LibreJoanne Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root { --primary: #000; --bg: #f4f7f6; --card: #ffffff; --border: #e1e8e7; --text: #333; --muted: #666; }
        body{font-family: -apple-system, system-ui, sans-serif; max-width:1000px; margin:0 auto; line-height:1.5; padding:2rem 1rem; background:var(--bg); color: var(--text); }
        
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem; }
        .header h1 { margin: 0; font-size: 1.5rem; letter-spacing: -0.5px; }
        
        .nav { display: flex; gap: 8px; margin-bottom: 2rem; background: #eee; padding: 4px; border-radius: 8px; width: fit-content; }
        .nav a { text-decoration: none; padding: 8px 20px; color: var(--muted); border-radius: 6px; font-weight: 600; font-size: 0.9rem; transition: 0.2s; }
        .nav a.active { background: var(--card); color: var(--primary); box-shadow: 0 2px 8px rgba(0,0,0,0.05); }

        .card { background: var(--card); border: 1px solid var(--border); padding: 1.5rem; margin-bottom: 1.5rem; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.03); }
        .card h3 { margin-top: 0; margin-bottom: 1.25rem; font-size: 1.1rem; border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
        
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 4px; }
        .form-group label { font-size: 0.8rem; font-weight: 700; color: var(--muted); text-transform: uppercase; }
        /* Names the URL parameter that overrides a setting per-request, so the
           reference sits next to the setting rather than only in the README.
           Room Key leads a device URL and so shows "?"; everything else is appended
           to it and shows "&", which is the form people actually need to type. */
        .param-hint { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                      font-size: 0.7rem; font-weight: 500; text-transform: none;
                      color: #9aa0a6; margin-left: 6px; }
        
        input[type=text], input[type=password], select, textarea { 
            padding: 10px; border: 1px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: #fafafa; transition: 0.2s;
        }
        input:focus { border-color: var(--primary); outline: none; background: #fff; }
        textarea { height: 100px; resize: vertical; }
        
        .btn { padding: 10px 20px; cursor: pointer; background: var(--primary); color: #fff; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; }
        .btn:hover { opacity: 0.8; }
        .btn-muted { background: #eee; color: var(--text); }
        .btn-danger { background: #fee; color: #c00; border: 1px solid #fcc; }

        .url-box { width: 100%; box-sizing: border-box; background: #f8f8f8; border: 1px dashed var(--border); font-family: monospace; font-size: 0.85rem; padding: 12px; border-radius: 8px; color: #000; cursor: pointer; margin: 8px 0; }
        
        .user-meta { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
        .actions { display: flex; gap: 12px; font-size: 0.85rem; font-weight: 600; align-items: center; }
        .actions a { text-decoration: none; color: #0066cc; }
        .actions a.delete { color: #cc0000; }
        /* Destructive actions are forms, not links, so they cannot be triggered by a
           cross-site GET. Keep them looking like the links beside them. */
        .linklike { background: none; border: 0; padding: 0; margin: 0; font: inherit;
                    cursor: pointer; color: #0066cc; }
        .linklike.delete { color: #cc0000; }
        .actions form { display: inline; margin: 0; }

        .search-area { background: #f9f9f9; padding: 1rem; border-radius: 8px; border: 1px solid var(--border); margin: 1rem 0; }
        .results-box { position: absolute; background: #fff; border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.1); border-radius: 8px; padding: 8px; z-index: 100; margin-top: 4px; width: 300px; display: none; }
        .results-box a { display: block; padding: 8px; text-decoration: none; color: var(--text); border-radius: 4px; }
        .results-box a:hover { background: #eee; }

        .cal-list { list-style: none; padding: 0; }
        .cal-item { display: flex; justify-content: space-between; align-items: center; padding: 10px; background: #fcfcfc; border: 1px solid var(--border); border-radius: 8px; margin-bottom: 8px; }
        
        .badge { font-size: 0.7rem; padding: 2px 8px; background: #eee; border-radius: 10px; font-weight: 700; text-transform: uppercase; }
    </style>
    <script>
        let currentPrefix = '';
        function searchCity(prefix) {
            currentPrefix = prefix;
            const searchInput = document.getElementById(prefix + 'city_search');
            const resDiv = document.getElementById(prefix + 'city_results');
            const name = searchInput.value;
            
            if (name.length < 2) return;
            
            resDiv.style.display = 'block';
            resDiv.innerHTML = '<small>Searching...</small>';
            
            fetch('geocoding.php?name=' + encodeURIComponent(name))
                .then(r => r.json())
                .then(data => {
                    if (data.length === 0) {
                        resDiv.innerHTML = '<small>No results found.</small>';
                        return;
                    }
                    resDiv.innerHTML = data.map(item => `
                        <a href="#" onclick="selectCity('${item.name.replace(/'/g, "\'")}', ${item.lat}, ${item.lon}); return false;">
                            <strong>${item.name}</strong>, <small>${item.admin} (${item.country})</small>
                        </a>
                    `).join('');
                });
        }
        function selectCity(name, lat, lon) {
            document.getElementById(currentPrefix + 'city').value = name;
            document.getElementById(currentPrefix + 'lat').value = lat;
            document.getElementById(currentPrefix + 'lon').value = lon;
            document.getElementById(currentPrefix + 'city_results').style.display = 'none';
            document.getElementById(currentPrefix + 'city_search').value = '';
        }
    </script>
</head>
<body>
    <div class="header">
        <h1>LibreJoanne</h1>
        <div style="display:flex; gap:12px; align-items:center;">
            <form method="POST" onsubmit="return confirm('Clear all calendar, RSS, and weather caches?')">
                <?= csrfField() ?>
                <button type="submit" name="clear_cache" class="btn btn-muted" style="padding: 6px 12px; font-size: 0.8rem;">Clear Caches</button>
            </form>
            <form method="POST" style="margin:0;">
                <?= csrfField() ?>
                <button type="submit" name="logout" class="linklike" style="font-size: 0.9rem; font-weight: 600; color: var(--muted);">Logout</button>
            </form>
        </div>
    </div>

    <div class="nav">
        <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">Users</a>
        <?php if ($_SESSION['is_admin']): ?>
        <a href="?tab=rooms" class="<?= $tab === 'rooms' ? 'active' : '' ?>">Rooms</a>
        <?php endif; ?>
    </div>

    <?php if($isFallbackConfig): ?>
        <div style="background:#fff3cd; color:#856404; padding:15px; border-radius:8px; margin-bottom:1.5rem; border:1px solid #ffeeba; font-weight:600;">
            ⚠️ Warning: <code>config.php</code> not found. Using <code>config.sample.php</code> defaults. 
            Please copy <code>web/data/config.sample.php</code> to <code>web/data/config.php</code> to customize your installation.
        </div>
    <?php endif; ?>

    <?php if($securityWarning) echo "<p style='color:white; background:#cc0000; padding:15px; border-radius:8px; font-weight:bold; border:2px solid #ff0000;'>" . e($securityWarning) . "</p>"; ?>
    <?php if($message) echo "<p style='color:green; background:#eaffea; padding:12px; border-radius:8px; font-weight:600;'>" . e($message) . "</p>"; ?>
    <?php if($error) echo "<p style='color:red; background:#ffeaea; padding:12px; border-radius:8px; font-weight:600;'>" . e($error) . "</p>"; ?>

    <?php if ($tab === 'users'): ?>
        <?php if ($_SESSION['is_admin']): ?>
        <div class="card">
            <h3>Create New User</h3>
            <form method="POST" class="form-grid" style="align-items: center;">
                <?= csrfField() ?>
                <input type="text" name="username" placeholder="Username (e.g. Matt)" required>
                <input type="password" name="password" placeholder="Password" required>
                <label style="display:flex; align-items:center; gap:8px; font-weight:600; font-size:0.9rem;">
                    <input type="checkbox" name="is_admin"> Admin User
                </label>
                <button type="submit" name="add_user" class="btn">Create User</button>
            </form>
        </div>
        <?php endif; ?>

        <?php foreach ($users as $user): 
            if (!$_SESSION['is_admin'] && $_SESSION['user_id'] != $user['id']) continue;
        ?>
            <div class="card">
                <div class="user-meta">
                    <div>
                        <h2 style="margin:0;"><?= htmlspecialchars($user['username']) ?> <?php if($user['is_admin']) echo '<span class="badge">Admin</span>'; ?></h2>
                        <small style="color:var(--muted)">Access Token: <code><?= e($user['access_token']) ?></code></small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Personal URL (Copy to Device)</label>
                    <input type="text" class="url-box" value="<?= e($baseUrl) ?>?userid=<?= e($user['access_token']) ?>" readonly onclick="this.select()">
                </div>

                <details style="margin-top:1rem; margin-bottom:1rem; background:#f8f8f8; padding:10px; border-radius:8px; border:1px solid #eee;">
                    <summary style="cursor:pointer; font-weight:600; font-size:0.85rem; color:var(--muted); text-transform:uppercase;">Security Settings</summary>
                    <form method="POST" style="margin-top:10px; display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                        <?= csrfField() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <input type="password" name="new_password" placeholder="Set New Password" style="flex:1; min-width:200px;">
                        
                        <?php if($_SESSION['is_admin'] && $_SESSION['user_id'] != $user['id']): ?>
                            <label style="display:flex; align-items:center; gap:6px; font-weight:600; font-size:0.9rem;">
                                <input type="checkbox" name="is_admin" <?= $user['is_admin'] ? 'checked' : '' ?>> Is Admin
                            </label>
                        <?php endif; ?>
                        
                        <button type="submit" name="save_user_security" class="btn btn-muted">Update Security</button>
                    </form>
                </details>

                <form method="POST" style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid #eee;">
                    <?= csrfField() ?>
                    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Display Label</label>
                            <input type="text" name="display_name" placeholder="e.g. Matt" value="<?= htmlspecialchars((string)$user['display_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Preferred View <span class="param-hint">&amp;view=</span></label>
                            <select name="view">
                                <option value="dashboard" <?= $user['view'] === 'dashboard' ? 'selected' : '' ?>>Dashboard</option>
                                <option value="grid" <?= $user['view'] === 'grid' ? 'selected' : '' ?>>7-Day Grid</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Time Format</label>
                            <select name="time_format">
                                <option value="auto" <?= ($user['time_format'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Language Default</option>
                                <option value="12h" <?= ($user['time_format'] ?? '') === '12h' ? 'selected' : '' ?>>12-Hour (1:00 PM)</option>
                                <option value="24h" <?= ($user['time_format'] ?? '') === '24h' ? 'selected' : '' ?>>24-Hour (13:00)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Timezone Override</label>
                            <input type="text" name="timezone" list="timezone-list" placeholder="e.g. Europe/London (Default: Config)" value="<?= e($user['timezone'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Language <span class="param-hint">&amp;lang=</span></label>
                            <select name="lang">
                                <option value="" <?= ($user['lang'] ?? '') === '' ? 'selected' : '' ?>>Site Default</option>
                                <option value="en" <?= ($user['lang'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
                                <option value="fr" <?= ($user['lang'] ?? '') === 'fr' ? 'selected' : '' ?>>Français</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Display <span class="param-hint">&amp;show_clock=</span></label>
                            <div style="padding:10px 0;">
                                <label style="text-transform:none; font-weight:600; color:var(--text);">
                                    <input type="checkbox" name="show_clock" <?= ($user['show_clock'] ?? 1) ? 'checked' : '' ?>> Show Clock
                                </label>
                            </div>
                        </div>
                    </div>

                    <div style="position:relative;">
                        <label style="font-size:0.8rem; font-weight:700; color:var(--muted);">Weather Location</label>
                        <div style="display:flex; gap:10px; margin-bottom:10px;">
                            <input type="text" id="user_<?= (int)$user['id'] ?>_city_search" placeholder="Search City..." style="flex:1;">
                            <button type="button" class="btn btn-muted" onclick="searchCity('user_<?= (int)$user['id'] ?>_')">Find</button>
                        </div>
                        <div id="user_<?= (int)$user['id'] ?>_city_results" class="results-box"></div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>City Name</label>
                            <input type="text" name="weather_city" id="user_<?= (int)$user['id'] ?>_city" value="<?= htmlspecialchars((string)$user['weather_city']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Lat</label>
                            <input type="text" name="weather_lat" id="user_<?= (int)$user['id'] ?>_lat" value="<?= htmlspecialchars((string)$user['weather_lat']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Lon</label>
                            <input type="text" name="weather_lon" id="user_<?= (int)$user['id'] ?>_lon" value="<?= htmlspecialchars((string)$user['weather_lon']) ?>">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Past Horizon (days)</label>
                            <input type="text" name="past_horizon" value="<?= htmlspecialchars((string)($user['past_horizon'] ?? '30')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Future Horizon (days)</label>
                            <input type="text" name="future_horizon" value="<?= htmlspecialchars((string)($user['future_horizon'] ?? '30')) ?>">
                        </div>
                    </div>
                    
                    <button type="submit" name="save_user_view" class="btn">Save User Settings</button>
                </form>

                <div style="margin-top:2rem;">
                    <label style="font-size:0.8rem; font-weight:700; color:var(--muted); text-transform:uppercase;">Calendar Feeds</label>
                    <ul class="cal-list">
                        <?php
                        $stmt = $pdo->prepare("SELECT * FROM calendars WHERE user_id = ?");
                        $stmt->execute([$user['id']]);
                        foreach ($stmt->fetchAll() as $cal): 
                            $isEditingCal = isset($_GET['edit_cal']) && $_GET['edit_cal'] == $cal['id'];
                            $decryptedUrl = $db->decrypt($cal['encrypted_url']);
                        ?>
                            <li class="cal-item">
                                <?php if ($isEditingCal): ?>
                                    <form method="POST" style="display:flex; gap:10px; width:100%;">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="cal_id" value="<?= (int)$cal['id'] ?>">
                                        <input type="text" name="url" value="<?= htmlspecialchars($decryptedUrl) ?>" style="flex:1;" required>
                                        <button type="submit" name="save_cal" class="btn">Update</button>
                                        <a href="?tab=users" class="btn btn-muted" style="text-decoration:none;">Cancel</a>
                                    </form>
                                <?php else: ?>
                                    <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; margin-right:10px;">
                                        <input type="text" class="url-box" value="<?= htmlspecialchars($decryptedUrl) ?>" readonly onclick="this.select()" style="margin:0; padding:4px; font-size:0.75rem;">
                                    </div>
                                    <div class="actions">
                                        <a href="?tab=users&edit_cal=<?= (int)$cal['id'] ?>">Edit</a>
                                        <form method="POST" onsubmit="return confirm('Delete this feed?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="delete_cal" value="<?= (int)$cal['id'] ?>">
                                            <button type="submit" class="linklike delete">Delete</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" style="display:flex; gap:10px; margin-top:10px;">
                        <?= csrfField() ?>
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <input type="text" name="url" placeholder="New iCal URL (https://...)" style="flex:1;" required>
                        <button type="submit" name="save_cal" class="btn btn-muted">Add Feed</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

    <?php elseif ($tab === 'rooms' && $_SESSION['is_admin']): ?>
        <div class="card">
            <h3><?= $editRoom ? "Edit Room: " . htmlspecialchars($editRoom['name']) : "Add New Room" ?></h3>
            <form method="POST">
                <?= csrfField() ?>
                <?php if($editRoom): ?><input type="hidden" name="room_id" value="<?= (int)$editRoom['id'] ?>"><?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Room Key <span class="param-hint">?room=</span></label>
                        <input type="text" name="room_key" placeholder="boardroom" value="<?= e($editRoom['room_key'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Room Name (Header)</label>
                        <input type="text" name="name" placeholder="The Boardroom" value="<?= htmlspecialchars((string)($editRoom['name'] ?? '')) ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Calendar URLs (one per line)</label>
                    <?php 
                        $existingUrls = "";
                        if($editRoom) {
                            $urls = json_decode($editRoom['calendar_url'], true) ?: [];
                            $existingUrls = implode("\n", $urls);
                        }
                    ?>
                    <textarea name="calendar_urls" placeholder="https://..."><?= htmlspecialchars($existingUrls) ?></textarea>
                </div>
                
                <div class="form-grid" style="margin-top:1rem;">
                    <div class="form-group">
                        <label>View Mode <span class="param-hint">&amp;view=</span></label>
                        <select name="view">
                            <option value="room" <?= ($editRoom['view'] ?? '') === 'room' ? 'selected' : '' ?>>Room Status</option>
                            <option value="dashboard" <?= ($editRoom['view'] ?? '') === 'dashboard' ? 'selected' : '' ?>>Personal Dashboard</option>
                            <option value="grid" <?= ($editRoom['view'] ?? '') === 'grid' ? 'selected' : '' ?>>7-Day Grid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Time Format</label>
                        <select name="time_format">
                            <option value="auto" <?= ($editRoom['time_format'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Language Default</option>
                            <option value="12h" <?= ($editRoom['time_format'] ?? '') === '12h' ? 'selected' : '' ?>>12-Hour (1:00 PM)</option>
                            <option value="24h" <?= ($editRoom['time_format'] ?? '') === '24h' ? 'selected' : '' ?>>24-Hour (13:00)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Timezone Override</label>
                        <input type="text" name="timezone" list="timezone-list" placeholder="e.g. Europe/London" value="<?= e($editRoom['timezone'] ?? '') ?>">
                    </div>
                    <div class="form-group">
                        <label>Language <span class="param-hint">&amp;lang=</span></label>
                        <select name="lang">
                            <option value="" <?= ($editRoom['lang'] ?? '') === '' ? 'selected' : '' ?>>Site Default</option>
                            <option value="en" <?= ($editRoom['lang'] ?? '') === 'en' ? 'selected' : '' ?>>English</option>
                            <option value="fr" <?= ($editRoom['lang'] ?? '') === 'fr' ? 'selected' : '' ?>>Français</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Display</label>
                        <div style="padding:10px 0; display:flex; flex-wrap:wrap; gap:14px;">
                            <label style="text-transform:none; font-weight:600; color:var(--text);"><input type="checkbox" name="show_clock" <?= ($editRoom['show_clock'] ?? 1) ? 'checked' : '' ?>> Clock <span class="param-hint">&amp;show_clock=</span></label>
                            <label style="text-transform:none; font-weight:600; color:var(--text);"><input type="checkbox" name="show_rss" <?= ($editRoom['show_rss'] ?? 1) ? 'checked' : '' ?>> RSS Ticker <span class="param-hint">&amp;show_rss=</span></label>
                            <label style="text-transform:none; font-weight:600; color:var(--text);"><input type="checkbox" name="show_weather" <?= ($editRoom['show_weather'] ?? 1) ? 'checked' : '' ?>> Weather <span class="param-hint">&amp;show_weather=</span></label>
                        </div>
                    </div>
                </div>

                <div class="search-area">
                    <label style="font-size:0.8rem; font-weight:700; color:var(--muted);">Location Search</label>
                    <div style="display:flex; gap:10px; margin-bottom:10px; position:relative;">
                        <input type="text" id="room_city_search" placeholder="City name..." style="flex:1;">
                        <button type="button" class="btn btn-muted" onclick="searchCity('room_')">Find</button>
                        <div id="room_city_results" class="results-box" style="top:100%; width:100%;"></div>
                    </div>
                    <div class="form-grid">
                        <input type="text" name="weather_city" id="room_city" placeholder="City" value="<?= htmlspecialchars((string)($editRoom['weather_city'] ?? '')) ?>">
                        <input type="text" name="weather_lat" id="room_lat" placeholder="Lat" value="<?= htmlspecialchars((string)($editRoom['weather_lat'] ?? '')) ?>">
                        <input type="text" name="weather_lon" id="room_lon" placeholder="Lon" value="<?= htmlspecialchars((string)($editRoom['weather_lon'] ?? '')) ?>">
                    </div>
                    <div class="form-grid" style="margin-top:1rem;">
                        <div class="form-group">
                            <label>Past Horizon (days)</label>
                            <input type="text" name="past_horizon" placeholder="30" value="<?= htmlspecialchars((string)($editRoom['past_horizon'] ?? '30')) ?>">
                        </div>
                        <div class="form-group">
                            <label>Future Horizon (days)</label>
                            <input type="text" name="future_horizon" placeholder="30" value="<?= htmlspecialchars((string)($editRoom['future_horizon'] ?? '30')) ?>">
                        </div>
                    </div>
                </div>
                
                <button type="submit" name="save_room" class="btn"><?= $editRoom ? "Update Room" : "Create Room" ?></button>
                <?php if($editRoom): ?><a href="?tab=rooms" class="btn btn-muted" style="text-decoration:none;">Cancel</a><?php endif; ?>
            </form>
        </div>

        <h2>Managed Rooms</h2>
        <?php foreach ($rooms as $room): ?>
            <div class="card">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1rem;">
                    <h3 style="margin:0; border:0; padding:0;"><?= htmlspecialchars($room['name']) ?> <span class="badge"><?= htmlspecialchars($room['room_key']) ?></span></h3>
                    <div class="actions">
                        <a href="?tab=rooms&edit_room=<?= (int)$room['id'] ?>">Edit</a>
                        <form method="POST" onsubmit="return confirm('Delete room?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="delete_room" value="<?= (int)$room['id'] ?>">
                            <button type="submit" class="linklike delete">Delete</button>
                        </form>
                    </div>
                </div>
                <div class="form-group">
                    <label><small>Room Display URL:</small></label>
                    <input type="text" class="url-box" value="<?= e($baseUrl) ?>?room=<?= e(urlencode($room['room_key'])) ?>" readonly onclick="this.select()">
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card" style="background:#eee;">
            <p><strong>Note:</strong> Rooms defined in <code>config.php</code> are still active but will be overridden by database rooms with the same key.</p>
        </div>
    <?php endif; ?>

    <datalist id="timezone-list">
        <?php foreach ($allTimezones as $tz): ?>
            <option value="<?= htmlspecialchars($tz) ?>"></option>
        <?php endforeach; ?>
    </datalist>
</body>
</html>
