<?php
// web/db.php — Database helper and security functions

class LibreDb {
    private $pdo;
    private $key;

    public function __construct($config) {
        $this->key = $config['security']['encryption_key'];
        $this->pdo = new PDO("sqlite:" . $config['security']['db_path']);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    private function init() {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password_hash TEXT,
            access_token TEXT UNIQUE,
            is_admin INTEGER DEFAULT 0,
            view TEXT DEFAULT 'dashboard',
            weather_lat REAL,
            weather_lon REAL,
            weather_city TEXT,
            display_name TEXT,
            time_format TEXT DEFAULT 'auto',
            past_horizon INTEGER DEFAULT 30,
            future_horizon INTEGER DEFAULT 30
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS calendars (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            encrypted_url TEXT,
            FOREIGN KEY(user_id) REFERENCES users(id)
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rooms (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            room_key TEXT UNIQUE,
            name TEXT,
            display_name TEXT,
            calendar_url TEXT,
            view TEXT DEFAULT 'room',
            time_format TEXT DEFAULT 'auto',
            show_rss INTEGER DEFAULT 1,
            show_weather INTEGER DEFAULT 1,
            weather_lat REAL,
            weather_lon REAL,
            weather_city TEXT,
            past_horizon INTEGER DEFAULT 30,
            future_horizon INTEGER DEFAULT 30
        )");

        // Self-healing: Add columns if they are missing from an older schema
        $this->ensureColumn('users', 'weather_lat', 'REAL');
        $this->ensureColumn('users', 'weather_lon', 'REAL');
        $this->ensureColumn('users', 'weather_city', 'TEXT');
        $this->ensureColumn('users', 'display_name', 'TEXT');
        $this->ensureColumn('users', 'time_format', 'TEXT');
        $this->ensureColumn('users', 'past_horizon', 'INTEGER');
        $this->ensureColumn('users', 'future_horizon', 'INTEGER');
        $this->ensureColumn('rooms', 'weather_lat', 'REAL');
        $this->ensureColumn('rooms', 'weather_lon', 'REAL');
        $this->ensureColumn('rooms', 'weather_city', 'TEXT');
        $this->ensureColumn('rooms', 'past_horizon', 'INTEGER');
        $this->ensureColumn('rooms', 'future_horizon', 'INTEGER');
        $this->ensureColumn('rooms', 'display_name', 'TEXT');
        $this->ensureColumn('rooms', 'time_format', 'TEXT');
    }

    private function ensureColumn($table, $column, $type) {
        $stmt = $this->pdo->query("PRAGMA table_info($table)");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $exists = false;
        foreach ($columns as $c) {
            if ($c['name'] === $column) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $this->pdo->exec("ALTER TABLE $table ADD COLUMN $column $type");
        }
    }

    public function getRoomConfig($key) {
        $stmt = $this->pdo->prepare("SELECT * FROM rooms WHERE room_key = ?");
        $stmt->execute([$key]);
        $room = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($room) {
            // Convert types back to what the app expects
            $room['calendar_url'] = json_decode($room['calendar_url'], true);
            $room['show_rss'] = (bool)$room['show_rss'];
            $room['show_weather'] = (bool)$room['show_weather'];
            $room['weather_lat'] = (float)$room['weather_lat'];
            $room['weather_lon'] = (float)$room['weather_lon'];
            $room['weather_city'] = (string)$room['weather_city'];
            $room['past_horizon'] = (int)($room['past_horizon'] ?: 30);
            $room['future_horizon'] = (int)($room['future_horizon'] ?: 30);
            $room['display_name'] = (string)$room['display_name'];
            $room['time_format'] = (string)($room['time_format'] ?: 'auto');
        }
        return $room;
    }

    public function encrypt($data) {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($data, 'aes-256-cbc', $this->key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public function decrypt($data) {
        $data = base64_decode($data);
        $ivSize = openssl_cipher_iv_length('aes-256-cbc');
        $iv = substr($data, 0, $ivSize);
        $encrypted = substr($data, $ivSize);
        return openssl_decrypt($encrypted, 'aes-256-cbc', $this->key, 0, $iv);
    }

    public function getCalendarsByToken($token) {
        $stmt = $this->pdo->prepare("
            SELECT encrypted_url FROM calendars 
            JOIN users ON calendars.user_id = users.id 
            WHERE users.access_token = ?
        ");
        $stmt->execute([$token]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $urls = [];
        foreach ($rows as $row) {
            $urls[] = $this->decrypt($row['encrypted_url']);
        }
        return $urls;
    }

    public function getPdo() { return $this->pdo; }
}
