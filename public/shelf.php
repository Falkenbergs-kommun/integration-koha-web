<?php
// RSS till JSON-konverterare för Falkenbergs bibliotek.
// Directus-first: RSS:en ger hyllmedlemskap (enda Koha-anropet), all bokmetadata
// hämtas i bulk från Directus och omslag från lokal bildcache. Vid Koha-fel
// serveras senast kända goda snapshot (HTTP 200 + stale: true).
require_once __DIR__ . '/../common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/../.env');

// Hämta parametrar från GET
$shelfNumber = isset($_GET['shelfnumber']) ? intval($_GET['shelfnumber']) : 247;
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';

// Validera format
if (!in_array($format, ['json', 'xml'])) {
    $format = 'json';
}

// Sätt Content-Type baserat på format
if ($format === 'xml') {
    header('Content-Type: application/xml; charset=utf-8');
} else {
    header('Content-Type: application/json; charset=utf-8');
}
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// Säkerställ att cache-katalogen finns
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Cache-fil (1h TTL), last-known-good-snapshot och fail-throttle-flagga
$cacheFile = "{$cacheDir}/cache_shelf{$shelfNumber}_{$format}.cache";
$snapshotFile = "{$cacheDir}/snapshot_shelf{$shelfNumber}_{$format}.cache";
$failFlagFile = "{$cacheDir}/fail_shelf{$shelfNumber}.flag";
$cacheMaxAge = 3600; // Cache i 1 timme

// Kolla om cache finns och är giltig
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

// Hämta konfiguration från .env
$baseUrl = getenv('BASE_URL') ?: 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/';
$rssUrl = "https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-shelves.pl?rss=1&op=view&shelfnumber={$shelfNumber}";

// Fail-throttle: vid nyligt Koha-fel, gå direkt på snapshot utan nytt försök
// (undviker att hamra ImCodes server under utfall/IP-bann)
if (recentFailureExists($failFlagFile)) {
    serveSnapshotOrError($snapshotFile, $format, [
        'status' => 'error',
        'message' => 'Koha tillfälligt onåbar (throttlad efter tidigare fel)'
    ]);
}

// Hämta RSS-feed — enda anropet mot Koha i hela requesten
$feedResult = fetchRssFeed($rssUrl);

// Kontrollera om hämtningen lyckades
if ($feedResult['data'] === false || $feedResult['http_code'] !== 200) {
    markFailure($failFlagFile);
    serveSnapshotOrError($snapshotFile, $format, [
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
    serveSnapshotOrError($snapshotFile, $format, [
        'status' => 'error',
        'message' => 'Kunde inte parsa XML-data'
    ]);
}

// Processa RSS-feed med metadata från Directus
$processed = processRssFeedFromDirectus($xml, $baseUrl);
$result = $processed['result'];
$itemCount = count($result['items']);

// Tom feed: skriv ingenting (Kohas bann kan ge tomma 200-svar som annars
// förgiftar cachen). Snapshot serveras om den finns; äkta tom hylla är legalt.
if ($itemCount === 0) {
    $stale = loadStaleSnapshot($snapshotFile, $format);
    if ($stale !== null) {
        echo $stale;
        exit;
    }
    echo $format === 'xml'
        ? generateXmlOutput($result)
        : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Directus nere men RSS ok: föredra snapshot (har metadata + omslag) framför
// degraderat RSS-only-svar. Utan snapshot serveras det degraderade svaret och
// TTL-cachas (men snapshottas inte).
if (!$processed['directus_ok']) {
    $stale = loadStaleSnapshot($snapshotFile, $format);
    if ($stale !== null) {
        echo $stale;
        exit;
    }
    $output = $format === 'xml'
        ? generateXmlOutput($result)
        : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($cacheFile, $output);
    echo $output;
    exit;
}

// Lyckad hämtning: nollställ fail-flagga, skriv TTL-cache + snapshot
@unlink($failFlagFile);

$output = $format === 'xml'
    ? generateXmlOutput($result)
    : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

file_put_contents($cacheFile, $output);
saveSnapshot($snapshotFile, $output, $itemCount);
echo $output;
