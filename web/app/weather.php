<?php
// web/app/weather.php — Custom weather backend using Open-Meteo

require_once __DIR__ . '/../lib/bootstrap.php';
[$config, $db] = LibreApp::boot();

LibreApp::jsonHeaders();

$lat = (float)($_GET['lat'] ?? 43.65); // Default Toronto
$lon = (float)($_GET['lon'] ?? -79.38);
$city = $_GET['city'] ?? 'Weather';

// Cache per location
$cacheFile = LibreApp::cachePath('weather', 'LibreJoanne_Weather_Salt_', $lat . $lon . $city, 'json');
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

$response = LibreHttp::get($url, 5);

if ($response === null) {
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

