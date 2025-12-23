<?php
// web/app/geocoding.php — City search proxy for Open-Meteo

header("Content-Type: application/json");

$configFile = __DIR__ . '/../data/config.php';
if (!file_exists($configFile)) {
    $configFile = __DIR__ . '/../data/config.sample.php';
}
$config = require $configFile;
require_once __DIR__ . "/../lib/db.php";

$name = $_GET['name'] ?? '';
if (strlen($name) < 2) {
    echo json_encode([]);
    exit;
}

$url = "https://geocoding-api.open-meteo.com/v1/search?name=" . urlencode($name) . "&count=5&language=en&format=json";

if (!LibreDb::isValidRemoteUrl($url)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid geocoding source URL"]);
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
    echo json_encode(["error" => "Geocoding service unavailable"]);
    exit;
}

$data = json_decode($response, true);
$results = [];

if (!empty($data['results'])) {
    foreach ($data['results'] as $item) {
        $results[] = [
            "name" => $item['name'],
            "admin" => $item['admin1'] ?? $item['country'] ?? '',
            "lat" => $item['latitude'],
            "lon" => $item['longitude'],
            "country" => $item['country']
        ];
    }
}

echo json_encode($results);

