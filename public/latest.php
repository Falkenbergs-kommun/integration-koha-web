<?php
// Endpoint för att hämta de senaste böckerna i katalogen (nyinköp).
// Directus-first HELT utan Koha-beroende: biblio-upptäckt från kft_koha_biblios
// (ofiltrerat) eller kft_koha_items (filtrerat), metadata i bulk från
// kft_koha_biblios, omslag från lokal bildcache + Syndetics. Directus synkas
// från Koha varje natt kl 03:00 — datat kan alltså släpa upp till ett dygn.
require_once __DIR__ . '/../common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/../.env');

// Hämta och validera format först — felsvar måste kunna skickas i rätt format
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';
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

// Golv OCH tak på limit. Utan golv går negativa värden vidare till Directus,
// där limit=-1 betyder "obegränsat" — en enda request skulle då dra hem hela
// katalogen (~68k poster) med bulk-metadata och omslagshämtning per bok.
$limit = isset($_GET['limit']) ? max(min(intval($_GET['limit']), 50), 1) : 10;

// Hämta och validera filter (kommaseparerade listor). Värdena ingår i
// cache-filnamnen, så de valideras strikt av parseFilterParam().
$filterParams = [
    'item_type_id' => [],
    'location'     => [],
    'ccode'        => [],
];
foreach (array_keys($filterParams) as $param) {
    $parsed = parseFilterParam($_GET[$param] ?? null, $param);
    if (!$parsed['ok']) {
        sendErrorResponse(400, $format, [
            'status' => 'error',
            'message' => $parsed['error']
        ]);
    }
    $filterParams[$param] = $parsed['values'];
}
$itemTypeIds = $filterParams['item_type_id'];
$locations   = $filterParams['location'];
$ccodes      = $filterParams['ccode'];

// Cache-fil baserat på format, antal och aktiva filter.
// Tomma suffix bevarar befintliga cache-nycklar oförändrade (bakåtkompatibilitet).
// Prefixen _loc_ och _cc_ undviker krock om samma värde (t.ex. "BARN") finns i flera filter.
$itemTypeSuffix = empty($itemTypeIds) ? '' : '_' . implode('_', $itemTypeIds);
$locationSuffix = empty($locations)   ? '' : '_loc_' . implode('_', $locations);
$ccodeSuffix    = empty($ccodes)      ? '' : '_cc_'  . implode('_', $ccodes);
$cacheFile = __DIR__ . "/../cache/cache_latest_{$limit}_{$format}{$itemTypeSuffix}{$locationSuffix}{$ccodeSuffix}.cache";
// Last-known-good-snapshot: serveras med stale-markering vid uppströmsfel
$snapshotFile = __DIR__ . "/../cache/snapshot_latest_{$limit}_{$format}{$itemTypeSuffix}{$locationSuffix}{$ccodeSuffix}.cache";
// Fail-throttle-flagga: delas mellan json/xml (samma Directus-anrop oavsett format)
$failFlagFile = __DIR__ . "/../cache/fail_latest_{$limit}{$itemTypeSuffix}{$locationSuffix}{$ccodeSuffix}.flag";
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

// Fail-throttle: vid nyligt Directus-fel, gå direkt på snapshot utan nytt försök
if (recentFailureExists($failFlagFile)) {
    serveSnapshotOrError($snapshotFile, $format, [
        'status' => 'error',
        'message' => 'Uppströmskälla tillfälligt onåbar (throttlad efter tidigare fel)'
    ]);
}

$baseUrl = getenv('BASE_URL') ?: 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/';

// Upptäck biblio_ids via Directus — olika kollektion beroende på filtrering
$hasFilters = !empty($itemTypeIds) || !empty($locations) || !empty($ccodes);
if (!$hasFilters) {
    $discovery = getLatestBiblioIdsFromDirectus($limit);
    $itemTypesMap = null; // Ofiltrerat svar har aldrig haft item_types (paritet med Koha-varianten)
} else {
    $discovery = getFilteredBiblioIdsFromDirectus($itemTypeIds, $locations, $ccodes, $limit);
    $itemTypesMap = $discovery['item_types_map'];
}

if (!$discovery['success']) {
    markFailure($failFlagFile);
    serveSnapshotOrError($snapshotFile, $format, [
        'status' => 'error',
        'message' => 'Kunde inte hämta data från Directus',
        'error' => $discovery['error']
    ]);
}

// Bulk-metadata från Directus + lokala omslag
$processed = processLatestBooksFromDirectus($discovery['ids'], $itemTypesMap, $baseUrl);
if (!$processed['directus_ok']) {
    markFailure($failFlagFile);
    serveSnapshotOrError($snapshotFile, $format, [
        'status' => 'error',
        'message' => 'Kunde inte hämta metadata från Directus'
    ]);
}
$result = $processed['result'];
$itemCount = count($result['items']);

// Tomt resultat: Directus success + tomt är pålitligt (ingen tyst felmod som
// Kohas utgångna token), men plötsligt tomt där data funnits är misstänkt —
// föredra snapshot. Skriver aldrig cache/snapshot (förgiftningsskydd).
if ($itemCount === 0) {
    $stale = loadStaleSnapshot($snapshotFile, $format);
    if ($stale !== null) {
        echo $stale;
        exit;
    }
    echo $format === 'xml'
        ? generateLatestXmlOutput($result)
        : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$output = $format === 'xml'
    ? generateLatestXmlOutput($result)
    : json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

file_put_contents($cacheFile, $output);
saveSnapshot($snapshotFile, $output, $itemCount);
echo $output;

/**
 * Bygg resultat från Directus-metadata. Samma item-struktur som tidigare
 * Koha-baserade processLatestBooks() — konsumenter ska inte märka skillnad.
 * $itemTypesMap: null för ofiltrerat läge (item_types blir null per item),
 * annars [biblio_id => [typ, ...]] från getFilteredBiblioIdsFromDirectus().
 */
function processLatestBooksFromDirectus($biblioIds, $itemTypesMap, $baseUrl) {
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

    if (empty($biblioIds)) {
        return ['result' => $result, 'directus_ok' => true];
    }

    $directus = getBiblioMetadataFromDirectus($biblioIds);
    if (!$directus['success']) {
        return ['result' => $result, 'directus_ok' => false];
    }

    foreach ($biblioIds as $biblioId) {
        // Biblio kan saknas i metadata-svaret (hunnit bli inactive) — hoppa över
        $bookData = $directus['books'][(string)$biblioId] ?? null;
        if ($bookData === null) {
            continue;
        }

        $firstIsbn = getFirstIsbn($bookData['isbn']);
        $image = resolveBookImageLocal($firstIsbn, $biblioId, $baseUrl);

        $catalogLink = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=' . $biblioId;

        $result['items'][] = [
            'title' => cleanTitle($bookData['title']),
            'link' => $catalogLink,
            'description' => $bookData['abstract'] ?? '',
            'pubDate' => date('r'),
            'guid' => $catalogLink,
            'biblio_id' => $biblioId,
            'isbn' => $bookData['isbn'],
            'isbn_clean' => $firstIsbn,
            'api_title' => cleanTitle($bookData['title']),
            'api_author' => $bookData['author'],
            'abstract' => $bookData['abstract'],
            'subtitle' => $bookData['subtitle'],
            'publisher' => $bookData['publisher'],
            'publication_year' => $bookData['publication_year'],
            'publication_place' => $bookData['publication_place'],
            'pages' => $bookData['pages'],
            'material_size' => $bookData['material_size'],
            'edition_statement' => $bookData['edition_statement'],
            'series_title' => $bookData['series_title'],
            'age_restriction' => $bookData['age_restriction'],
            'url' => $bookData['url'],
            'ean' => $bookData['ean'],
            'notes' => $bookData['notes'],
            'image_url' => $image['image_url'],
            'image_cached' => $image['image_cached'],
            'image_cached_url' => $image['image_cached_url'],
            'item_types' => $itemTypesMap === null ? null : ($itemTypesMap[$biblioId] ?? [])
        ];
    }

    return ['result' => $result, 'directus_ok' => true];
}

/**
 * Generera XML-output för senaste böcker (använder samma struktur som generateXmlOutput)
 */
function generateLatestXmlOutput($result) {
    return generateXmlOutput($result);
}
?>
