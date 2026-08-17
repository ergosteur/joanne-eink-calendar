<?php
// calendar.php — Merges one or more iCal feeds and returns JSON

require_once __DIR__ . '/../lib/bootstrap.php';
[$config, $db] = LibreApp::boot();

$calConfig = $config['calendar'];

$ctx = LibreContext::resolve($config, $db, $_GET);

$activeTimezone = $ctx->timezone;
date_default_timezone_set($activeTimezone);

$urls = $ctx->calendarUrls;

function isValidWebUrl($url) {
    return LibreDb::isValidRemoteUrl($url);
}

$CACHE_TTL  = $calConfig['cache_ttl'];
$pastDays   = $ctx->pastHorizon;
$futureDays = $ctx->futureHorizon;

LibreApp::jsonHeaders();

function getICS($url, $ttl) {
    $cacheFile = LibreApp::cachePath('calendar', 'LibreJoanne_Salt_', $url, 'ics');

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        return file_get_contents($cacheFile);
    }

    // Anything that is not a valid public URL is treated as a local template, which is
    // executed. That path must stay pinned to the single bundled generator.
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
        $ics = LibreHttp::get($url, 10);
    }

    if ($ics === false || $ics === null || empty($ics)) {
        return file_exists($cacheFile) ? file_get_contents($cacheFile) : false;
    }

    file_put_contents($cacheFile, $ics);
    return $ics;
}

$displayTz = new DateTimeZone($activeTimezone);
$feedDefaultTz = LibreIcal::timezone((string)$calConfig['timezone'], $displayTz);

// Recurrence is expanded against this window, so the horizons bound the work.
$windowStart = (new DateTimeImmutable('now', $displayTz))->modify("-{$pastDays} days");
$windowEnd   = (new DateTimeImmutable('now', $displayTz))->modify("+{$futureDays} days");

$events = [];

foreach ($urls as $url) {
    $ics = getICS($url, $CACHE_TTL);
    if ($ics === false) continue;

    foreach (LibreIcal::parseEvents($ics, $feedDefaultTz, $displayTz, $windowStart, $windowEnd) as $event) {
        $events[] = $event;
    }
}

// Sort all merged events by START time
usort($events, fn($a, $b) => $a["start"] <=> $b["start"]);

$now = new DateTime("now", new DateTimeZone($activeTimezone));
$current = null;
$next = null;
$upcoming = [];
// Same bounds the parser expanded against, so the display list cannot disagree
// with what recurrence produced.
$pastHorizon = $windowStart;
$futureHorizon = $windowEnd;

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
            "start_iso" => $event["start"]->format(DateTime::ATOM),
            "end_iso" => $event["end"]->format(DateTime::ATOM),
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
    "now_ts" => $now->getTimestamp(),
    "status" => $current ? "IN_USE" : "AVAILABLE",
    "current" => $current ? [
        "summary" => $current["summary"],
        "ends" => $current["end"]->format("H:i"),
        "ends_iso" => $current["end"]->format(DateTime::ATOM),
        "is_allday" => $current["is_allday"]
    ] : null,
    "next" => $next ? [
        "summary" => $next["summary"],
        "date" => $next["start"]->format("Y-m-d"),
        "time" => $next["start"]->format("H:i"),
        "ends" => $next["end"]->format("H:i"),
        "start_iso" => $next["start"]->format(DateTime::ATOM),
        "end_iso" => $next["end"]->format(DateTime::ATOM),
        "duration" => round(($next["end"]->getTimestamp() - $next["start"]->getTimestamp()) / 60),
        "same_day" => $next["start"]->format("Y-m-d") === $now->format("Y-m-d"),
        "is_allday" => $next["is_allday"]
    ] : null,
    "upcoming" => $upcoming
];

echo json_encode($response, JSON_PRETTY_PRINT);
