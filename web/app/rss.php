<?php
// web/rss.php — Fetches multiple RSS feeds based on language

require_once __DIR__ . '/../lib/bootstrap.php';
[$config, $db] = LibreApp::boot();
$rssConfig = $config['rss'];

LibreApp::jsonHeaders();

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
$urls = array_filter($urls, [LibreDb::class, 'isValidRemoteUrl']);

function fetchCached($url, $ttl) {
    $cacheFile = LibreApp::cachePath('rss', 'LibreJoanne_RSS_Salt_', $url, 'xml');

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
        return file_get_contents($cacheFile);
    }

    $opts = [
        "http" => [
            "method" => "GET",
            "header" => "User-Agent: LibreJoanne/1.0\r\n",
            "timeout" => 10
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

// Interleave the feeds, but hold the order steady for the length of a cache window.
//
// The XML is cached, yet the JSON was reshuffled on every request, so each poll
// returned the same headlines in a new order. The client pages through that list, so
// on an e-ink panel the ticker rearranged itself every refresh — visible churn, and a
// full-page repaint each time on a battery-powered display.
$window = (int)floor(time() / max(1, (int)$rssConfig['cache_ttl']));
$allEvents = stableShuffle($allEvents, crc32($lang . '|' . $window));

function stableShuffle(array $items, int $seed): array
{
    // Fisher-Yates against a seeded generator, rather than shuffle(), so the ordering
    // does not depend on the global random state.
    mt_srand($seed);
    for ($i = count($items) - 1; $i > 0; $i--) {
        $j = mt_rand(0, $i);
        [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
    }
    mt_srand(); // Restore unpredictable seeding for anything later in the request.
    return $items;
}

echo json_encode($allEvents, JSON_PRETTY_PRINT);