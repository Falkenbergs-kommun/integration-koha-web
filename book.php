<?php
// Endpoint för att hämta data om en enskild bok från Koha API
require_once __DIR__ . '/common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/.env');

// Hämta parametrar från GET
$biblioId = isset($_GET['biblionumber']) ? intval($_GET['biblionumber']) : null;
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'json';

// Validera biblio ID
if (!$biblioId || $biblioId <= 0) {
    http_response_code(400);
    $errorResponse = [
        'status' => 'error',
        'message' => 'Saknar eller ogiltigt biblionumber. Använd ?biblionumber=12345'
    ];

    if ($format === 'xml') {
        header('Content-Type: application/xml; charset=utf-8');
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response></response>');
        $xml->addChild('status', 'error');
        $xml->addChild('message', htmlspecialchars($errorResponse['message']));
        echo $xml->asXML();
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
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

// Cache-fil baserat på biblionumber och format
$cacheFile = __DIR__ . "/cache/cache_book{$biblioId}_{$format}.cache";
$cacheMaxAge = 3600; // Cache i 1 timme

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
    http_response_code(500);
    $errorResponse = [
        'status' => 'error',
        'message' => 'Kunde inte hämta OAuth-token'
    ];

    if ($format === 'xml') {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response></response>');
        $xml->addChild('status', 'error');
        $xml->addChild('message', htmlspecialchars($errorResponse['message']));
        echo $xml->asXML();
    } else {
        echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// Hämta bokdata från API
$bookData = getBookDataFromApi($biblioId, $apiBaseUrl, $apiToken);

// Kontrollera om boken hittades
if (!$bookData['title'] && !$bookData['isbn']) {
    http_response_code(404);
    $errorResponse = [
        'status' => 'error',
        'message' => 'Ingen bok hittades med biblionumber ' . $biblioId
    ];

    if ($format === 'xml') {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response></response>');
        $xml->addChild('status', 'error');
        $xml->addChild('message', htmlspecialchars($errorResponse['message']));
        echo $xml->asXML();
    } else {
        echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    exit;
}

// Extrahera första ISBN och bygg bildURL
$firstIsbn = getFirstIsbn($bookData['isbn']);
$imageUrl = getImageUrl($firstIsbn);

// Cacha bilden lokalt
$cachedImagePath = null;
$cachedImageFullUrl = null;
if ($imageUrl) {
    $cachedImagePath = cacheImage($firstIsbn, $imageUrl);
    if ($cachedImagePath && $baseUrl) {
        $cachedImageFullUrl = $baseUrl . $cachedImagePath;
    }
}

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
    'notes' => $bookData['notes'],
    'image_url' => $imageUrl,
    'image_cached' => $cachedImagePath,
    'image_cached_url' => $cachedImageFullUrl,
    'catalog_link' => "https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber={$biblioId}"
];

// Generera output baserat på format
if ($format === 'xml') {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response></response>');

    foreach ($result as $key => $value) {
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
