<?php
// web/app/weather.php — Custom weather backend using Open-Meteo

$configFile = __DIR__ . '/../data/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../data/config.sample.php';
}
$config = require $configFile;
require_once __DIR__ . "/../lib/db.php";
$db = new LibreDb($config);

header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$lat = (float)($_GET['lat'] ?? 43.65); // Default Toronto
$lon = (float)($_GET['lon'] ?? -79.38);
$city = $_GET['city'] ?? 'Weather';

// Cache per location
$cacheSalt = "LibreJoanne_Weather_Salt_";
$cacheFile = __DIR__ . "/../data/cache/weather.cache." . md5($cacheSalt . $lat . $lon . $city) . ".json";
$ttl = 900; // 15 minutes

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
    echo file_get_contents($cacheFile);
    exit;
}

$url = "https://api.open-meteo.com/v1/forecast?latitude=$lat&longitude=$lon&current=temperature_2m,weather_code&daily=weather_code,temperature_2m_max&timezone=auto&forecast_days=8";

if (!LibreDb::isValidRemoteUrl($url)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid weather source URL"]);
    exit;
}

$opts = [
    "http" => [
        "method" => "GET",
        "header" => "User-Agent: LibreJoanne/1.0\r\n",
        "timeout" => 5
    ]
];
$context = stream_context_create($opts);
$response = @file_get_contents($url, false, $context);

if ($response === false) {
    http_response_code(503);
    echo json_encode(["error" => "Weather service unavailable"]);
    exit;
}

$data = json_decode($response, true);
$current = $data['current'] ?? null;

if (!$current) {
    echo json_encode(["error" => "No weather data found"]);
    exit;
}

$result = [
    "city" => $city,
    "temp" => round($current['temperature_2m']),
    "code" => $current['weather_code'],
    "unit" => $data['current_units']['temperature_2m'] ?? '°C',
    "daily" => []
];

if (!empty($data['daily'])) {
    for ($i = 0; $i < count($data['daily']['time']); $i++) {
        $result['daily'][] = [
            "day" => $data['daily']['time'][$i],
            "code" => $data['daily']['weather_code'][$i],
            "max" => round($data['daily']['temperature_2m_max'][$i])
        ];
    }
}

$json = json_encode($result);
file_put_contents($cacheFile, $json);
echo $json;

