<?php
// RSS till JSON-konverterare med dynamisk lista via GET-parameter.
// Directus-first (samma mönster som shelf.php): Koha-RSS:en ger bara
// listmedlemskap — ett enda Koha-anrop — och all bokmetadata hämtas i bulk
// från Directus. Tidigare gjordes ett Koha-API-anrop PER bok, vilket är
// precis det som triggade ImCodes fail2ban. Vid Koha-fel serveras senast
// kända goda snapshot (HTTP 200 + stale: true).
require_once __DIR__ . '/../common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/../.env');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// Hämta lista-ID från GET-parameter
$rawListId = isset($_GET['id']) ? trim($_GET['id']) : '';

if ($rawListId === '') {
    sendErrorResponse(400, 'json', [
        'status' => 'error',
        'message' => 'Lista-ID saknas. Använd ?id=XXX i URL:en'
    ]);
}

// Validera strikt: bara positiva heltal. is_numeric() släppte igenom "1e5",
// "0.5" och liknande, som blev egna cache-filer för samma underliggande lista.
if (!ctype_digit($rawListId) || intval($rawListId) <= 0) {
    sendErrorResponse(400, 'json', [
        'status' => 'error',
        'message' => 'Ogiltigt lista-ID. Måste vara ett positivt heltal.'
    ]);
}
$listId = intval($rawListId);

// Säkerställ att cache-katalogen finns
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Cache-fil (1h TTL), last-known-good-snapshot och fail-throttle-flagga
$cacheFile = "{$cacheDir}/cache_list_{$listId}.json";
$snapshotFile = "{$cacheDir}/snapshot_list_{$listId}.json";
$failFlagFile = "{$cacheDir}/fail_list_{$listId}.flag";
$cacheMaxAge = 3600; // Cache i 1 timme

// Kolla om cache finns och är giltig
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

// Hämta konfiguration från .env
$baseUrl = getenv('BASE_URL') ?: 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/';
$rssUrl = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-shelves.pl?rss=1&op=view&shelfnumber=' . $listId;

// Fail-throttle: vid nyligt Koha-fel, gå direkt på snapshot utan nytt försök
// (undviker att hamra ImCodes server under utfall/IP-bann)
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
$result['list_id'] = $listId;
$itemCount = count($result['items']);

// Tom feed: skriv ingenting (Kohas bann kan ge tomma 200-svar som annars
// förgiftar cachen). Snapshot serveras om den finns; äkta tom lista är legal.
if ($itemCount === 0) {
    $stale = loadStaleSnapshot($snapshotFile, 'json');
    if ($stale !== null) {
        echo $stale;
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Directus nere men RSS ok: föredra snapshot (har metadata + omslag) framför
// degraderat RSS-only-svar. Utan snapshot serveras det degraderade svaret och
// TTL-cachas (men snapshottas inte).
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
