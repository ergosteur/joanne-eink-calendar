<?php
// web/manage.php — Optimized User and Room management dashboard

session_start();
$config = require __DIR__ . "/../data/config.php";
require_once __DIR__ . "/../lib/db.php";

$db = new LibreDb($config);
$pdo = $db->getPdo();

$error = "";
$message = "";
$tab = $_GET['tab'] ?? 'users';

function clearAllCaches() {
    $files = glob(__DIR__ . '/../data/*.{ics,xml,json}', GLOB_BRACE);
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

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: manage.php");
    exit;
}

// SETUP FLOW: First time admin creation
if (!$adminExists) {
    if (isset($_POST['setup'])) {
        if ($_POST['setup_password'] === $config['security']['setup_password']) {
            $token = bin2hex(random_bytes(16));
            $hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, access_token, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute([$_POST['username'], $hash, $token]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['is_admin'] = true;
            $message = "Setup complete! Admin account created.";
            $adminExists = true;
        } else {
            $error = "Incorrect Setup Password.";
        }
    }
}
// LOGIN FLOW
else if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['login'])) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$_POST['username']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($_POST['password'], $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['is_admin'] = (bool)$user['is_admin'];
        } else {
            $error = "Invalid username or password.";
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
            <p style="color:#666; font-size:0.9rem;">Enter the setup password from config.php to create your admin account.</p>
            <?php if($error) echo "<p style='color:red'>$error</p>"; ?>
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
            <?php if($error) echo "<p style='color:red'>$error</p>"; ?>
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
    $stmt = $pdo->prepare("INSERT INTO users (username, access_token) VALUES (?, ?)");
    try {
        $stmt->execute([$_POST['username'], $token]);
        $message = "User created! Access token: $token";
    } catch (Exception $e) { $error = "Username already exists."; }
}

if (isset($_POST['save_user_view'])) {
    $stmt = $pdo->prepare("UPDATE users SET view = ?, weather_lat = ?, weather_lon = ?, weather_city = ?, display_name = ?, past_horizon = ?, future_horizon = ? WHERE id = ?");
    $stmt->execute([$_POST['view'], $_POST['weather_lat'], $_POST['weather_lon'], $_POST['weather_city'], $_POST['display_name'], $_POST['past_horizon'], $_POST['future_horizon'], $_POST['user_id']]);
    clearAllCaches();
    $message = "User preferences updated and caches cleared.";
}

if (isset($_POST['save_cal'])) {
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

if (isset($_GET['delete_cal'])) {
    $stmt = $pdo->prepare("DELETE FROM calendars WHERE id = ?");
    $stmt->execute([$_GET['delete_cal']]);
    clearAllCaches();
}

// Room Management (Admin Only)
if ($_SESSION['is_admin']) {
    if (isset($_POST['save_room'])) {
        $urls = array_filter(array_map('trim', explode("\n", $_POST['calendar_urls'])));
        if (!empty($_POST['room_id'])) {
            $stmt = $pdo->prepare("UPDATE rooms SET room_key=?, name=?, calendar_url=?, view=?, show_rss=?, show_weather=?, weather_lat=?, weather_lon=?, weather_city=?, past_horizon=?, future_horizon=? WHERE id=?");
            $stmt->execute([
                $_POST['room_key'], $_POST['name'], json_encode($urls), $_POST['view'],
                isset($_POST['show_rss']) ? 1 : 0, isset($_POST['show_weather']) ? 1 : 0, 
                $_POST['weather_lat'], $_POST['weather_lon'], $_POST['weather_city'], 
                $_POST['past_horizon'], $_POST['future_horizon'], $_POST['room_id']
            ]);
            $message = "Room updated!";
        } else {
            $stmt = $pdo->prepare("INSERT INTO rooms (room_key, name, calendar_url, view, show_rss, show_weather, weather_lat, weather_lon, weather_city, past_horizon, future_horizon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            try {
                $stmt->execute([
                    $_POST['room_key'], $_POST['name'], json_encode($urls), $_POST['view'],
                    isset($_POST['show_rss']) ? 1 : 0, isset($_POST['show_weather']) ? 1 : 0,
                    $_POST['weather_lat'], $_POST['weather_lon'], $_POST['weather_city'],
                    $_POST['past_horizon'], $_POST['future_horizon']
                ]);
                $message = "Room created!";
            } catch (Exception $e) { $error = "Room key already exists."; }
        }
        clearAllCaches();
        $editRoom = null;
    }

    if (isset($_GET['delete_room'])) {
        $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
        $stmt->execute([$_GET['delete_room']]);
        clearAllCaches();
    }

    if (isset($_POST['clear_cache'])) {
        clearAllCaches();
        $message = "All caches cleared manually.";
    }
}

$users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
$rooms = $pdo->query("SELECT * FROM rooms")->fetchAll(PDO::FETCH_ASSOC);

// Dynamically detect base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$dir = dirname($_SERVER['PHP_SELF']);
if ($dir === '/' || $dir === '\\') $dir = '';
$baseUrl = "$protocol://$host$dir/";
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
        .actions { display: flex; gap: 12px; font-size: 0.85rem; font-weight: 600; }
        .actions a { text-decoration: none; color: #0066cc; }
        .actions a.delete { color: #cc0000; }

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
                <button type="submit" name="clear_cache" class="btn btn-muted" style="padding: 6px 12px; font-size: 0.8rem;">Clear Caches</button>
            </form>
            <a href="?logout=1" style="font-size: 0.9rem; font-weight: 600; color: var(--muted); text-decoration: none;">Logout</a>
        </div>
    </div>

    <div class="nav">
        <a href="?tab=users" class="<?= $tab === 'users' ? 'active' : '' ?>">Users</a>
        <?php if ($_SESSION['is_admin']): ?>
        <a href="?tab=rooms" class="<?= $tab === 'rooms' ? 'active' : '' ?>">Rooms</a>
        <?php endif; ?>
    </div>

    <?php if($message) echo "<p style='color:green; background:#eaffea; padding:12px; border-radius:8px; font-weight:600;'>$message</p>"; ?>
    <?php if($error) echo "<p style='color:red; background:#ffeaea; padding:12px; border-radius:8px; font-weight:600;'>$error</p>"; ?>

    <?php if ($tab === 'users'): ?>
        <?php if ($_SESSION['is_admin']): ?>
        <div class="card">
            <h3>Create New User</h3>
            <form method="POST" class="form-grid">
                <input type="text" name="username" placeholder="Username (e.g. Matt)" required>
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
                        <small style="color:var(--muted)">Access Token: <code><?= $user['access_token'] ?></code></small>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Personal URL (Copy to Device)</label>
                    <input type="text" class="url-box" value="<?= $baseUrl ?>index.php?room=personal&userid=<?= $user['access_token'] ?>" readonly onclick="this.select()">
                </div>

                <form method="POST" style="margin-top:1.5rem; padding-top:1rem; border-top:1px solid #eee;">
                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Display Label</label>
                            <input type="text" name="display_name" placeholder="e.g. Matt" value="<?= htmlspecialchars((string)$user['display_name']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Preferred View</label>
                            <select name="view">
                                <option value="dashboard" <?= $user['view'] === 'dashboard' ? 'selected' : '' ?>>Dashboard</option>
                                <option value="grid" <?= $user['view'] === 'grid' ? 'selected' : '' ?>>7-Day Grid</option>
                            </select>
                        </div>
                    </div>

                    <div style="position:relative;">
                        <label style="font-size:0.8rem; font-weight:700; color:var(--muted);">Weather Location</label>
                        <div style="display:flex; gap:10px; margin-bottom:10px;">
                            <input type="text" id="user_<?= $user['id'] ?>_city_search" placeholder="Search City..." style="flex:1;">
                            <button type="button" class="btn btn-muted" onclick="searchCity('user_<?= $user['id'] ?>_')">Find</button>
                        </div>
                        <div id="user_<?= $user['id'] ?>_city_results" class="results-box"></div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>City Name</label>
                            <input type="text" name="weather_city" id="user_<?= $user['id'] ?>_city" value="<?= htmlspecialchars((string)$user['weather_city']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Lat</label>
                            <input type="text" name="weather_lat" id="user_<?= $user['id'] ?>_lat" value="<?= htmlspecialchars((string)$user['weather_lat']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Lon</label>
                            <input type="text" name="weather_lon" id="user_<?= $user['id'] ?>_lon" value="<?= htmlspecialchars((string)$user['weather_lon']) ?>">
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
                                        <input type="hidden" name="cal_id" value="<?= $cal['id'] ?>">
                                        <input type="text" name="url" value="<?= htmlspecialchars($decryptedUrl) ?>" style="flex:1;" required>
                                        <button type="submit" name="save_cal" class="btn">Update</button>
                                        <a href="?tab=users" class="btn btn-muted" style="text-decoration:none;">Cancel</a>
                                    </form>
                                <?php else: ?>
                                    <div style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1; margin-right:10px;">
                                        <input type="text" class="url-box" value="<?= htmlspecialchars($decryptedUrl) ?>" readonly onclick="this.select()" style="margin:0; padding:4px; font-size:0.75rem;">
                                    </div>
                                    <div class="actions">
                                        <a href="?tab=users&edit_cal=<?= $cal['id'] ?>">Edit</a>
                                        <a href="?tab=users&delete_cal=<?= $cal['id'] ?>" class="delete" onclick="return confirm('Delete?')">Delete</a>
                                    </div>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="POST" style="display:flex; gap:10px; margin-top:10px;">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
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
                <?php if($editRoom): ?><input type="hidden" name="room_id" value="<?= $editRoom['id'] ?>"><?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Room Key (URL Slug)</label>
                        <input type="text" name="room_key" placeholder="boardroom" value="<?= $editRoom['room_key'] ?? '' ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Display Name</label>
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
                        <label>View Mode</label>
                        <select name="view">
                            <option value="room" <?= ($editRoom['view'] ?? '') === 'room' ? 'selected' : '' ?>>Room Status</option>
                            <option value="dashboard" <?= ($editRoom['view'] ?? '') === 'dashboard' ? 'selected' : '' ?>>Personal Dashboard</option>
                            <option value="grid" <?= ($editRoom['view'] ?? '') === 'grid' ? 'selected' : '' ?>>7-Day Grid</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Options</label>
                        <div style="padding:10px 0;">
                            <label><input type="checkbox" name="show_rss" <?= ($editRoom['show_rss'] ?? 1) ? 'checked' : '' ?>> RSS Ticker</label>
                            <label style="margin-left:15px;"><input type="checkbox" name="show_weather" <?= ($editRoom['show_weather'] ?? 1) ? 'checked' : '' ?>> Weather</label>
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
                        <a href="?tab=rooms&edit_room=<?= $room['id'] ?>">Edit</a>
                        <a href="?tab=rooms&delete_room=<?= $room['id'] ?>" class="delete" onclick="return confirm('Delete room?')">Delete</a>
                    </div>
                </div>
                <div class="form-group">
                    <label><small>Room Display URL:</small></label>
                    <input type="text" class="url-box" value="<?= $baseUrl ?>index.php?room=<?= urlencode($room['room_key']) ?>" readonly onclick="this.select()">
                </div>
            </div>
        <?php endforeach; ?>

        <div class="card" style="background:#eee;">
            <p><strong>Note:</strong> Rooms defined in <code>config.php</code> are still active but will be overridden by database rooms with the same key.</p>
        </div>
    <?php endif; ?>
</body>
</html>
