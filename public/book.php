<?php
// Endpoint för att hämta data om en enskild bok från Koha API
require_once __DIR__ . '/../common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/../.env');

// Hämta och validera format först — felsvar måste kunna skickas i rätt format
$format = isset($_GET['format']) && is_string($_GET['format']) ? strtolower($_GET['format']) : 'json';
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
// Svaren är JSON/XML, aldrig HTML. nosniff hindrar webbläsaren från att gissa
// annat, vilket gör XSS via ekad cache-utdata omöjligt även i teorin.
header('X-Content-Type-Options: nosniff');

// Biblionummer: bara positiva heltal (samma regel som ?id i list.php).
// intval() släppte tidigare igenom "12abc" och arrayer (intval([x]) === 1).
$rawBiblioId = isset($_GET['biblionumber']) && is_string($_GET['biblionumber']) ? trim($_GET['biblionumber']) : '';
if (!ctype_digit($rawBiblioId) || intval($rawBiblioId) <= 0) {
    sendErrorResponse(400, $format, [
        'status' => 'error',
        'message' => 'Saknar eller ogiltigt biblionumber. Använd ?biblionumber=12345'
    ]);
}
$biblioId = intval($rawBiblioId);

// Säkerställ att cache-katalogen finns
$cacheDir = __DIR__ . '/../cache';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

// Cache-fil baserat på biblionumber och format
$cacheFile = "{$cacheDir}/cache_book{$biblioId}_{$format}.cache";
// Negativ cache: markerar att biblionumret inte finns i Koha. Utan den ger
// iteration över godtyckliga biblionummer ett Koha-anrop per request, eftersom
// 404-svar annars aldrig cachas. Formatoberoende — existensen är densamma.
$missFile = "{$cacheDir}/miss_book{$biblioId}.flag";
// Fail-throttle vid Koha-fel, delad för alla böcker (samma uppströmskälla)
$failFlagFile = "{$cacheDir}/fail_book.flag";
$cacheMaxAge = 3600; // Cache i 1 timme
$missMaxAge = intval(getenv('CACHE_TTL_BOOK_MISS') ?: 3600); // Negativ cache 1 timme

// Kolla om cache finns och är giltig
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheMaxAge) {
    echo file_get_contents($cacheFile);
    exit;
}

// Känd miss inom TTL: svara 404 utan att röra Koha
if (file_exists($missFile) && (time() - filemtime($missFile)) < $missMaxAge) {
    sendErrorResponse(404, $format, [
        'status' => 'error',
        'message' => 'Ingen bok hittades med biblionumber ' . $biblioId
    ]);
}

// Fail-throttle: vid nyligt Koha-fel, svara direkt utan nytt försök
if (recentFailureExists($failFlagFile)) {
    sendErrorResponse(503, $format, [
        'status' => 'error',
        'message' => 'Koha tillfälligt onåbar (throttlad efter tidigare fel)'
    ]);
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
    markFailure($failFlagFile);
    sendErrorResponse(500, $format, [
        'status' => 'error',
        'message' => 'Kunde inte hämta OAuth-token'
    ]);
}

// Hämta bokdata från API
$bookData = getBookDataFromApi($biblioId, $apiBaseUrl, $apiToken);

// Kontrollera om boken hittades
if (!$bookData['title'] && !$bookData['isbn']) {
    @touch($missFile);
    sendErrorResponse(404, $format, [
        'status' => 'error',
        'message' => 'Ingen bok hittades med biblionumber ' . $biblioId
    ]);
}

// Lyckad hämtning: nollställ fail-flagga
@unlink($failFlagFile);

// Extrahera första ISBN och resolva bildtrippel (Syndetics eller Kohas lokala omslag)
$firstIsbn = getFirstIsbn($bookData['isbn']);
$image = resolveBookImage($firstIsbn, $biblioId, $apiBaseUrl, $baseUrl);

// Bygg resultat
$result = [
    'status' => 'ok',
    'cached_at' => date('Y-m-d H:i:s'),
    'biblio_id' => $biblioId,
    'isbn' => $bookData['isbn'],
    'isbn_clean' => $firstIsbn,
    'title' => cleanTitle($bookData['title']),
    'author' => $bookData['author'],
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
    'issn' => $bookData['issn'],
    'notes' => $bookData['notes'],
    'creation_date' => $bookData['creation_date'],
    'koha_timestamp' => $bookData['timestamp'],
    'copyright_date' => $bookData['copyright_date'],
    'lc_control_number' => $bookData['lc_control_number'],
    'serial' => $bookData['serial'],
    'image_url' => $image['image_url'],
    'image_cached' => $image['image_cached'],
    'image_cached_url' => $image['image_cached_url'],
    'catalog_link' => "https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber={$biblioId}"
];

// Generera output baserat på format
if ($format === 'xml') {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response></response>');

    foreach ($result as $key => $value) {
        // Array-fält (t.ex. series_title) serialiseras som JSON — htmlspecialchars
        // på array är fatal TypeError i PHP 8. Samma skydd som generateXmlOutput().
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if ($value !== null && $value !== '') {
            $xml->addChild($key, htmlspecialchars($value));
        } else {
            $xml->addChild($key);
        }
    }

    // Formatera XML med indentation
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    $xmlOutput = $dom->saveXML();
    file_put_contents($cacheFile, $xmlOutput);
    echo $xmlOutput;
} else {
    $jsonOutput = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    file_put_contents($cacheFile, $jsonOutput);
    echo $jsonOutput;
}
?>
