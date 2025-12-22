<?php
// web/rss.php — Fetches multiple RSS feeds based on language

$config = require __DIR__ . "/../data/config.php";
$rssConfig = $config['rss'];

header("Content-Type: application/json");
header("Cache-Control: no-store");

$lang = $_GET['lang'] ?? 'en';
$urls = array_merge(
    $rssConfig[$lang] ?? [],
    $rssConfig['all'] ?? []
);

if (empty($urls)) {
    echo json_encode([]);
    exit;
}

function fetchCached($url, $ttl) {
    $cacheFile = __DIR__ . "/../data/rss.cache." . md5($url) . ".xml";
    
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        return file_get_contents($cacheFile);
    }

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: LibreJoanne/1.0\r\n"
        ]
    ];
    $context = stream_context_create($opts);
    $content = @file_get_contents($url, false, $context);
    
    if ($content !== false) {
        file_put_contents($cacheFile, $content);
        return $content;
    }
    
    return file_exists($cacheFile) ? file_get_contents($cacheFile) : false;
}

$allEvents = [];

foreach ($urls as $url) {
    $xmlString = fetchCached($url, $rssConfig['cache_ttl']);
    if ($xmlString === false) continue;

    $xml = @simplexml_load_string($xmlString);
    if (!$xml) continue;

    $count = 0;
    // RSS 2.0
    if (isset($xml->channel->item)) {
        foreach ($xml->channel->item as $item) {
            $allEvents[] = [
                "title" => (string)$item->title,
                "source" => (string)$xml->channel->title
            ];
            if (++$count >= 5) break;
        }
    } 
    // Atom
    else if (isset($xml->entry)) {
        foreach ($xml->entry as $entry) {
            $allEvents[] = [
                "title" => (string)$entry->title,
                "source" => (string)$xml->title
            ];
            if (++$count >= 5) break;
        }
    }
}

// Shuffle to mix feeds if multiple
shuffle($allEvents);

echo json_encode($allEvents, JSON_PRETTY_PRINT);