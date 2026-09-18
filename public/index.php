<?php
// RSS till JSON-konverterare för Falkenbergs bibliotek (fast lista 247).
// Directus-first (samma mönster som shelf.php/list.php): Koha-RSS:en ger bara
// listmedlemskap — ett enda Koha-anrop — och all bokmetadata hämtas i bulk
// från Directus i stället för ett API-anrop per bok.
require_once __DIR__ . '/../common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// Säkerställ att cache-katalogen finns
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$cacheFile = "{$cacheDir}/cache.json";
$snapshotFile = "{$cacheDir}/snapshot_index.json";
$failFlagFile = "{$cacheDir}/fail_index.flag";
$cacheMaxAge = 3600; // Cache i 1 timme

// Kolla om cache finns och är giltig
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

$baseUrl = getenv('BASE_URL') ?: 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/';
$rssUrl = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-shelves.pl?rss=1&op=view&shelfnumber=247';

// Fail-throttle: vid nyligt Koha-fel, gå direkt på snapshot utan nytt försök
if (recentFailureExists($failFlagFile)) {
    serveSnapshotOrError($snapshotFile, 'json', [
        'status' => 'error',
        'message' => 'Koha tillfälligt onåbar (throttlad efter tidigare fel)'
    ]);
}

// Hämta RSS-feed — enda anropet mot Koha i hela requesten
$feedResult = fetchRssFeed($rssUrl);

if ($feedResult['data'] === false || $feedResult['http_code'] !== 200) {
    markFailure($failFlagFile);
    serveSnapshotOrError($snapshotFile, 'json', [
        'status' => 'error',
        'message' => 'Kunde inte hämta RSS-feed',
        'error' => $feedResult['error'],
        'http_code' => $feedResult['http_code']
    ]);
}

// Parsa XML
$xml = simplexml_load_string($feedResult['data']);
if ($xml === false) {
    markFailure($failFlagFile);
    serveSnapshotOrError($snapshotFile, 'json', [
        'status' => 'error',
        'message' => 'Kunde inte parsa XML-data'
    ]);
}

// Processa RSS-feed med metadata från Directus
$processed = processRssFeedFromDirectus($xml, $baseUrl);
$result = $processed['result'];
$itemCount = count($result['items']);

// Tom feed: skriv aldrig cache (tomma 200-svar från Koha förgiftar annars cachen)
if ($itemCount === 0) {
    $stale = loadStaleSnapshot($snapshotFile, 'json');
    if ($stale !== null) {
        echo $stale;
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Directus nere men RSS ok: föredra snapshot framför degraderat RSS-only-svar
if (!$processed['directus_ok']) {
    $stale = loadStaleSnapshot($snapshotFile, 'json');
    if ($stale !== null) {
        echo $stale;
        exit;
    }
    $output = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($cacheFile, $output);
    echo $output;
    exit;
}

// Lyckad hämtning: nollställ fail-flagga, skriv TTL-cache + snapshot
@unlink($failFlagFile);

$output = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
file_put_contents($cacheFile, $output);
saveSnapshot($snapshotFile, $output, $itemCount);
echo $output;
