<?php
// Endpoint för att hämta de senaste böckerna i katalogen (nyinköp)
require_once __DIR__ . '/../common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/../.env');

// Hämta format från GET
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';
$limit = isset($_GET['limit']) ? min(intval($_GET['limit']), 50) : 10;

// Hämta och validera item_type_id filter (kommaseparerad lista)
$itemTypeIds = [];
if (isset($_GET['item_type_id']) && !empty(trim($_GET['item_type_id']))) {
    $itemTypeIds = array_filter(
        array_map('trim', array_map('strtoupper', explode(',', $_GET['item_type_id']))),
        function($id) { return !empty($id); }
    );
    sort($itemTypeIds); // Normalisera ordning för konsistenta cache-nycklar
}

// Hämta och validera location filter (kommaseparerad lista, samma mönster som item_type_id)
$locations = [];
if (isset($_GET['location']) && !empty(trim($_GET['location']))) {
    $locations = array_filter(
        array_map('trim', array_map('strtoupper', explode(',', $_GET['location']))),
        function($v) { return !empty($v); }
    );
    sort($locations);
}

// Hämta och validera ccode filter (kommaseparerad lista, mappas till collection_code i common.php)
$ccodes = [];
if (isset($_GET['ccode']) && !empty(trim($_GET['ccode']))) {
    $ccodes = array_filter(
        array_map('trim', array_map('strtoupper', explode(',', $_GET['ccode']))),
        function($v) { return !empty($v); }
    );
    sort($ccodes);
}

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

// Cache-fil baserat på format, antal och aktiva filter.
// Tomma suffix bevarar befintliga cache-nycklar oförändrade (bakåtkompatibilitet).
// Prefixen _loc_ och _cc_ undviker krock om samma värde (t.ex. "BARN") finns i flera filter.
$itemTypeSuffix = empty($itemTypeIds) ? '' : '_' . implode('_', $itemTypeIds);
$locationSuffix = empty($locations)   ? '' : '_loc_' . implode('_', $locations);
$ccodeSuffix    = empty($ccodes)      ? '' : '_cc_'  . implode('_', $ccodes);
$cacheFile = __DIR__ . "/../cache/cache_latest_{$limit}_{$format}{$itemTypeSuffix}{$locationSuffix}{$ccodeSuffix}.cache";
// Last-known-good-snapshot: serveras med stale-markering vid Koha-fel
$snapshotFile = __DIR__ . "/../cache/snapshot_latest_{$limit}_{$format}{$itemTypeSuffix}{$locationSuffix}{$ccodeSuffix}.cache";
$cacheMaxAge = intval(getenv('CACHE_TTL_LATEST') ?: 3600); // Standard 1 timme

// Säkerställ att cache-katalogen finns
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Kolla om cache finns och är giltig
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

// Hämta konfiguration från .env
$baseUrl = getenv('BASE_URL') ?: 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/';
$apiBaseUrl = getenv('API_BASE_URL');
$oauthUrl = getenv('OAUTH_URL');
$clientId = getenv('CLIENT_ID');
$clientSecret = getenv('CLIENT_SECRET');

// Hämta OAuth-token
$apiToken = getOAuthToken($oauthUrl, $clientId, $clientSecret);
if (!$apiToken) {
    serveSnapshotOrError($snapshotFile, $format, ['status' => 'error', 'message' => 'Kunde inte hämta OAuth-token']);
}

// Hämta biblios - olika strategi beroende på om någon filtrering används
$hasFilters = !empty($itemTypeIds) || !empty($locations) || !empty($ccodes);
if (!$hasFilters) {
    // Ingen filtrering - använd direkt biblios endpoint (snabbast)
    $bibliosUrl = rtrim($apiBaseUrl, '/') . '?_order_by=-biblio_id&_per_page=' . $limit;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $bibliosUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        serveSnapshotOrError($snapshotFile, $format, [
            'status' => 'error',
            'message' => 'Kunde inte hämta senaste böckerna från API',
            'http_code' => $httpCode,
            'error' => $error
        ]);
    }

    $biblios = json_decode($response, true);
} else {
    // Filtrering på item_type_id, location och/eller ccode - använd items endpoint med biblio embed
    $biblios = getFilteredBibliosFromItems($apiBaseUrl, $apiToken, $itemTypeIds, $limit, $locations, $ccodes);
}

// Validera resultat
if (!is_array($biblios)) {
    serveSnapshotOrError($snapshotFile, $format, ['status' => 'error', 'message' => 'Ogiltigt svar från API']);
}

// getFilteredBibliosFromItems() returnerar [] vid API-fel — tomt filtrerat
// resultat får inte förgifta cachen, servera snapshot om möjligt
if ($hasFilters && empty($biblios)) {
    serveSnapshotOrError($snapshotFile, $format, ['status' => 'error', 'message' => 'Inga träffar eller API-fel vid filtrering']);
}

// Processa böckerna och hämta fullständig metadata
$result = processLatestBooks($biblios, $apiBaseUrl, $apiToken, $baseUrl);

// Generera output baserat på format. TTL-cache och snapshot skrivs bara vid
// minst 1 item (tomma svar får inte förgifta cache eller snapshot).
$itemCount = count($result['items']);
$output = $format === 'xml'
    ? generateLatestXmlOutput($result)
    : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

if ($itemCount > 0) {
    file_put_contents($cacheFile, $output);
    saveSnapshot($snapshotFile, $output, $itemCount);
}
echo $output;

/**
 * Processa biblios från API och berika med metadata och bilder
 * Använder samma struktur som processRssFeed för konsistens
 */
function processLatestBooks($biblios, $apiBaseUrl, $apiToken, $baseUrl) {
    $result = [
        'status' => 'ok',
        'cached_at' => date('Y-m-d H:i:s'),
        'channel' => [
            'title' => 'Senaste böckerna i katalogen',
            'link' => 'https://bibliotekskatalog.falkenberg.se',
            'description' => 'Nyinköp och senast tillagda böcker',
            'language' => 'sv',
            'lastBuildDate' => date('r')
        ],
        'items' => []
    ];

    foreach ($biblios as $biblio) {
        // Hämta biblio_id
        $biblioId = $biblio['biblio_id'] ?? null;

        if (!$biblioId) {
            continue;
        }

        // Extrahera första ISBN och resolva bildtrippel (Syndetics eller Kohas lokala omslag)
        $firstIsbn = getFirstIsbn($biblio['isbn'] ?? null);
        $image = resolveBookImage($firstIsbn, $biblioId, $apiBaseUrl, $baseUrl);

        // Bygg länk till katalogposten
        $catalogLink = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=' . $biblioId;

        $result['items'][] = [
            'title' => cleanTitle($biblio['title'] ?? null),
            'link' => $catalogLink,
            'description' => $biblio['abstract'] ?? '',
            'pubDate' => date('r'),
            'guid' => $catalogLink,
            'biblio_id' => $biblioId,
            'isbn' => $biblio['isbn'] ?? null,
            'isbn_clean' => $firstIsbn,
            'api_title' => cleanTitle($biblio['title'] ?? null),
            'api_author' => $biblio['author'] ?? null,
            'abstract' => $biblio['abstract'] ?? null,
            'subtitle' => $biblio['subtitle'] ?? null,
            'publisher' => $biblio['publisher'] ?? null,
            'publication_year' => $biblio['publication_year'] ?? null,
            'publication_place' => $biblio['publication_place'] ?? null,
            'pages' => $biblio['pages'] ?? null,
            'material_size' => $biblio['material_size'] ?? null,
            'edition_statement' => $biblio['edition_statement'] ?? null,
            'series_title' => $biblio['series_title'] ?? null,
            'age_restriction' => $biblio['age_restriction'] ?? null,
            'url' => $biblio['url'] ?? null,
            'ean' => $biblio['ean'] ?? null,
            'notes' => $biblio['notes'] ?? null,
            'image_url' => $image['image_url'],
            'image_cached' => $image['image_cached'],
            'image_cached_url' => $image['image_cached_url'],
            'item_types' => $biblio['item_types'] ?? null
        ];
    }

    return $result;
}

/**
 * Generera XML-output för senaste böcker (använder samma struktur som generateXmlOutput)
 */
function generateLatestXmlOutput($result) {
    return generateXmlOutput($result);
}

// generateErrorXml() delas numera från common.php
?>
