<?php
// web/rss.php — Fetches multiple RSS feeds based on language

$config = require __DIR__ . "/../data/config.php";
$rssConfig = $config['rss'];

header("Content-Type: application/json");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$lang = $_GET['lang'] ?? 'en';
$urls = array_merge(
    $rssConfig[$lang] ?? [],
    $rssConfig['all'] ?? []
);

if (empty($urls)) {
    echo json_encode([]);
    exit;
}

// Security: Validate URLs (SSRF Protection)
$urls = array_filter($urls, function($url) {
    $parsed = parse_url($url);
    return isset($parsed['scheme'], $parsed['host']) && 
           ($parsed['scheme'] === 'http' || $parsed['scheme'] === 'https');
});

function fetchCached($url, $ttl) {
    $cacheSalt = "LibreJoanne_RSS_Salt_";
    $cacheFile = __DIR__ . "/../data/cache/rss.cache." . md5($cacheSalt . $url) . ".xml";
    
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