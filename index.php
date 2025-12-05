<?php
// RSS till JSON-konverterare för Falkenbergs bibliotek med ISBN-hämtning
require_once __DIR__ . '/common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/.env');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

$cacheFile = __DIR__ . '/cache.json';
$cacheMaxAge = 3600; // Cache i 1 timme

// Kolla om cache finns och är giltig
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

$rssUrl = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-shelves.pl?rss=1&op=view&shelfnumber=247';
$apiBaseUrl = getenv('API_BASE_URL');
$oauthUrl = getenv('OAUTH_URL');
$clientId = getenv('CLIENT_ID');
$clientSecret = getenv('CLIENT_SECRET');

// Hämta RSS-feed
$feedResult = fetchRssFeed($rssUrl);

// Kontrollera om hämtningen lyckades
if ($feedResult['data'] === false || $feedResult['http_code'] !== 200) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kunde inte hämta RSS-feed',
        'error' => $feedResult['error'],
        'http_code' => $feedResult['http_code']
    ]);
    exit;
}

// Parsa XML
$xml = simplexml_load_string($feedResult['data']);
if ($xml === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kunde inte parsa XML-data'
    ]);
    exit;
}

// Hämta OAuth-token
$apiToken = getOAuthToken($oauthUrl, $clientId, $clientSecret);
if (!$apiToken) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kunde inte hämta OAuth-token'
    ]);
    exit;
}

// Processa RSS-feed
$result = processRssFeed($xml, $apiBaseUrl, $apiToken);

// Spara till cache
$jsonOutput = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
file_put_contents($cacheFile, $jsonOutput);

// Returnera JSON
echo $jsonOutput;
?>
