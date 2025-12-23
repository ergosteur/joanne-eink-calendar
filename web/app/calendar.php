<?php
// calendar.php — Merges one or more iCal feeds and returns JSON

$configFile = __DIR__ . '/../data/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../data/config.sample.php';
}
$config = require $configFile;
require_once __DIR__ . "/../lib/db.php";
$db = new LibreDb($config);

$calConfig = $config['calendar'];

$roomId = $_GET['room'] ?? 'default';

if (!empty($_GET['userid'])) {
    $roomId = 'personal';
}

// Try DB first, then config.php
$roomConfig = $db->getRoomConfig($roomId);
if (!$roomConfig) {
    $roomConfig = $config['rooms'][$roomId] ?? $config['rooms']['default'];
}

$activeTimezone = $roomConfig['timezone'] ?: $calConfig['timezone'];

if ($roomId === 'personal' && !empty($_GET['userid'])) {
    $stmt = $db->getPdo()->prepare("SELECT timezone FROM users WHERE access_token = ?");
    $stmt->execute([$_GET['userid']]);
    $userTz = $stmt->fetchColumn();
    if ($userTz) {
        $activeTimezone = $userTz;
    }
}

date_default_timezone_set($activeTimezone);

$urls = is_array($roomConfig['calendar_url']) 
        ? $roomConfig['calendar_url'] 
        : [$roomConfig['calendar_url']];

// Check for Database override via userid token
if (!empty($_GET['userid'])) {
    $dbUrls = $db->getCalendarsByToken($_GET['userid']);
    if (!empty($dbUrls)) {
        $urls = $dbUrls;
    }
}

// Security: Validate URLs (SSRF Protection)
function isValidWebUrl($url) {
    return LibreDb::isValidRemoteUrl($url);
}

// Allow overriding via ?cal= only for the personal room
if ($roomId === 'personal' && !empty($_GET['cal'])) {
    $overrides = is_array($_GET['cal']) ? $_GET['cal'] : [$_GET['cal']];
    // Strict filtering for user input
    $overrides = array_filter($overrides, 'isValidWebUrl');
    $urls = array_merge($urls, $overrides);
}

// Note: We do NOT filter $urls here because we trust Config/DB URLs to contain local paths if needed.

function unescapeIcal($text) {
    return str_replace(['\\,', '\\;', '\\\\', '\\n', '\\N'], [',', ';', '\\', "\n", "\n"], $text);
}

$CACHE_TTL  = $calConfig['cache_ttl'];
$pastDays = (int)($roomConfig['past_horizon'] ?? 30);
$futureDays = (int)($roomConfig['future_horizon'] ?? 30);

if ($roomId === 'personal' && !empty($_GET['userid'])) {
    $stmt = $db->getPdo()->prepare("SELECT past_horizon, future_horizon FROM users WHERE access_token = ?");
    $stmt->execute([$_GET['userid']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        $pastDays = (int)($user['past_horizon'] ?: $pastDays);
        $futureDays = (int)($user['future_horizon'] ?: $futureDays);
    }
}

header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

function getICS($url, $ttl) {
    // Salt the cache filename to prevent guessing from known URLs
    $cacheSalt = "LibreJoanne_Salt_";
    $cacheFile = __DIR__ . "/../data/cache/calendar.cache." . md5($cacheSalt . $url) . ".ics";
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        return file_get_contents($cacheFile);
    }

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: LibreJoanne/1.0\r\n",
            "timeout" => 10, // 10 second timeout for remote fetches
        ]
    ];
    $context = stream_context_create($opts);
    
    // Resolve relative paths if it's a local file
    $fetchUrl = $url;
    $isLocal = !isValidWebUrl($url);
    if ($isLocal) {
        // Security: Only allow demo.ics.php as a local source
        if (basename($url) !== 'demo.ics.php') {
            return false;
        }

        $baseDir = realpath(__DIR__);
        $fetchUrl = realpath(__DIR__ . "/" . basename($url));
        
        // Ensure the path is within the allowed directory
        if (!$fetchUrl || !str_starts_with($fetchUrl, $baseDir)) {
            return false;
        }

        // Execute the local PHP demo file
        ob_start();
        include $fetchUrl;
        $ics = ob_get_clean();
    } else {
        $ics = @file_get_contents($fetchUrl, false, $context);
    }
    
    if ($ics === false || empty($ics)) {
        return file_exists($cacheFile) ? file_get_contents($cacheFile) : false;
    }

    file_put_contents($cacheFile, $ics);
    return $ics;
}

function parseIcsDate($dateStr, $timezone) {
    // Handle DATE-only format: 20251225
    if (strlen($dateStr) === 8) {
        return DateTime::createFromFormat('!Ymd', $dateStr, new DateTimeZone($timezone));
    }
    // UTC format: 20231221T150000Z
    if (str_ends_with($dateStr, 'Z')) {
        return DateTime::createFromFormat('Ymd\THis\Z', $dateStr, new DateTimeZone('UTC'));
    }
    // Local format: 20231221T150000
    return DateTime::createFromFormat('Ymd\THis', $dateStr, new DateTimeZone($timezone));
}

$events = [];

foreach ($urls as $url) {
    $ics = getICS($url, $CACHE_TTL);
    if ($ics === false) continue;

    // Unfold lines
    $ics = preg_replace('/\r\n[\x20\x09]/', '', $ics);

    if (preg_match_all('/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $matches)) {
        foreach ($matches[1] as $block) {
            $event = [];
            $isAllDay = false;
            
            // DTSTART
            if (preg_match('/^DTSTART(?:;VALUE=(DATE)|;TZID=([^:]+))?:(\d+T?\d*Z?)/m', $block, $m)) {
                $tzName = !empty($m[2]) ? $m[2] : $calConfig['timezone'];
                $event['start'] = parseIcsDate($m[3], $tzName);
                if ($m[1] === 'DATE') $isAllDay = true;
            }
            
            // DTEND
            if (preg_match('/^DTEND(?:;VALUE=(DATE)|;TZID=([^:]+))?:(\d+T?\d*Z?)/m', $block, $m)) {
                $tzName = !empty($m[2]) ? $m[2] : $activeTimezone;
                $event['end'] = parseIcsDate($m[3], $tzName);
            }
            
            // SUMMARY
            if (preg_match('/^SUMMARY:(.*)/m', $block, $m)) {
                $event['summary'] = unescapeIcal(trim($m[1]));
            }

            if (isset($event['start'], $event['end'], $event['summary'])) {
                $event['is_allday'] = $isAllDay;
                
                // If it's a timed event, convert it from its source TZ to local TZ
                if (!$isAllDay) {
                    $event['start']->setTimezone(new DateTimeZone($activeTimezone));
                    $event['end']->setTimezone(new DateTimeZone($activeTimezone));
                }
                // All-day events were parsed directly into local timezone by parseIcsDate
                
                $events[] = $event;
            }
        }
    }
}

// Sort all merged events by START time
usort($events, fn($a, $b) => $a["start"] <=> $b["start"]);

$now = new DateTime("now", new DateTimeZone($activeTimezone));
$current = null;
$next = null;
$upcoming = [];
$pastHorizon = (clone $now)->modify("-{$pastDays} days");
$futureHorizon = (clone $now)->modify("+{$futureDays} days");

foreach ($events as $event) {
    // 1. Identify currently active meeting
    if ($event['start'] <= $now && $event['end'] > $now) {
        $current = $event;
    }
    
    // 2. Identify the soonest future meeting (must end after now and start after or during now)
    if ($event['start'] > $now && !$next) {
        $next = $event;
    }

    // 3. Collect for display list
    if ($event['end'] >= $pastHorizon && $event['start'] <= $futureHorizon) {
        $upcoming[] = [
            "summary" => $event["summary"],
            "date" => $event["start"]->format("Y-m-d"),
            "time" => $event["start"]->format("H:i"),
            "ends" => $event["end"]->format("H:i"),
            "duration" => round(($event["end"]->getTimestamp() - $event["start"]->getTimestamp()) / 60),
            "is_today" => $event["start"]->format("Y-m-d") === $now->format("Y-m-d"),
            "is_allday" => $event["is_allday"],
            "start_ts" => $event["start"]->getTimestamp(),
            "end_ts" => $event["end"]->getTimestamp()
        ];
    }
}

// Build response
$response = [
    "now" => $now->format(DateTime::ATOM),
    "status" => $current ? "IN USE" : "AVAILABLE",
    "current" => $current ? [
        "summary" => $current["summary"],
        "ends" => $current["end"]->format("H:i"),
        "is_allday" => $current["is_allday"]
    ] : null,
    "next" => $next ? [
        "summary" => $next["summary"],
        "date" => $next["start"]->format("Y-m-d"),
        "time" => $next["start"]->format("H:i"),
        "ends" => $next["end"]->format("H:i"),
        "duration" => round(($next["end"]->getTimestamp() - $next["start"]->getTimestamp()) / 60),
        "same_day" => $next["start"]->format("Y-m-d") === $now->format("Y-m-d"),
        "is_allday" => $next["is_allday"]
    ] : null,
    "upcoming" => $upcoming
];

echo json_encode($response, JSON_PRETTY_PRINT);
