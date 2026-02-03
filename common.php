<?php
// Gemensamma funktioner för bibliotekssystemet

// Enkel .env-laddare utan externa beroenden
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        return;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Hoppa över kommentarer
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parsa KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Ta bort citattecken om de finns
            $value = trim($value, '"\'');

            if (!empty($key)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

// Funktion för att hämta OAuth-token
function getOAuthToken($oauthUrl, $clientId, $clientSecret) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $oauthUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => $clientId,
        'client_secret' => $clientSecret
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['access_token'])) {
            return $data['access_token'];
        }
    }

    return null;
}

// Funktion för att hämta bokdata från API
function getBookDataFromApi($biblioId, $apiBaseUrl, $apiToken) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiBaseUrl . $biblioId);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);

        return [
            'isbn' => $data['isbn'] ?? null,
            'title' => $data['title'] ?? null,
            'author' => $data['author'] ?? null,
            'abstract' => $data['abstract'] ?? null,
            'subtitle' => $data['subtitle'] ?? null,
            'publisher' => $data['publisher'] ?? null,
            'publication_year' => $data['publication_year'] ?? null,
            'publication_place' => $data['publication_place'] ?? null,
            'pages' => $data['pages'] ?? null,
            'material_size' => $data['material_size'] ?? null,
            'edition_statement' => $data['edition_statement'] ?? null,
            'series_title' => $data['series_title'] ?? null,
            'age_restriction' => $data['age_restriction'] ?? null,
            'url' => $data['url'] ?? null,
            'ean' => $data['ean'] ?? null,
            'issn' => $data['issn'] ?? null,
            'notes' => $data['notes'] ?? null,
            'creation_date' => $data['creation_date'] ?? null,
            'timestamp' => $data['timestamp'] ?? null,
            'copyright_date' => $data['copyright_date'] ?? null,
            'lc_control_number' => $data['lc_control_number'] ?? null,
            'serial' => $data['serial'] ?? null
        ];
    }

    return [
        'isbn' => null,
        'title' => null,
        'author' => null,
        'abstract' => null,
        'subtitle' => null,
        'publisher' => null,
        'publication_year' => null,
        'publication_place' => null,
        'pages' => null,
        'material_size' => null,
        'edition_statement' => null,
        'series_title' => null,
        'age_restriction' => null,
        'url' => null,
        'ean' => null,
        'issn' => null,
        'notes' => null,
        'creation_date' => null,
        'timestamp' => null,
        'copyright_date' => null,
        'lc_control_number' => null,
        'serial' => null
    ];
}

// Extrahera biblio ID från URL
function extractBiblioId($url) {
    // URL-format: .../opac-detail.pl?biblionumber=55738
    if (preg_match('/biblionumber=(\d+)/', $url, $matches)) {
        return $matches[1];
    }
    return null;
}

// Funktion för att extrahera första ISBN från fält som kan innehålla flera
function getFirstIsbn($isbnString) {
    if (!$isbnString) {
        return null;
    }

    // Hantera flera ISBN separerade med | eller ,
    $parts = preg_split('/\s*[|,]\s*/', trim($isbnString));
    $firstIsbn = trim($parts[0]);

    // Ta bort eventuella bindestreck och mellanslag
    $cleanIsbn = preg_replace('/[\s-]/', '', $firstIsbn);

    return $cleanIsbn ?: null;
}

// Funktion för att rensa avslutande / och : från titel
function cleanTitle($title) {
    if (!$title) {
        return null;
    }

    // Ta bort avslutande /, :, och whitespace
    return rtrim(trim($title), '/: ');
}

// Funktion för att bygga bildURL från ISBN
function getImageUrl($isbn) {
    if (!$isbn) {
        return null;
    }

    $client = getenv('SYNDETICS_CLIENT') ?: 'bibfalken';
    return "https://secure.syndetics.com/index.aspx?isbn={$isbn}/LC.JPG&client={$client}&type=xw12";
}

// Funktion för att ladda ner och cacha bild
function cacheImage($isbn, $syndeticsUrl) {
    if (!$isbn || !$syndeticsUrl) {
        return null;
    }

    $imageDir = __DIR__ . '/images';
    $imageFilename = $isbn . '.jpg';
    $imagePath = $imageDir . '/' . $imageFilename;
    $imageWebPath = 'images/' . $imageFilename;

    // Om bilden redan finns cachad, returnera den
    if (file_exists($imagePath)) {
        return $imageWebPath;
    }

    // Skapa images-mappen om den inte finns
    if (!is_dir($imageDir)) {
        mkdir($imageDir, 0755, true);
    }

    // Ladda ner bilden från Syndetics
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $syndeticsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // Kontrollera att det är en riktig bild (inte fel-sida)
    if ($httpCode === 200 && $imageData && strpos($contentType, 'image') !== false) {
        // Spara bilden
        if (file_put_contents($imagePath, $imageData)) {
            return $imageWebPath;
        }
    }

    return null;
}

// Funktion för att hämta RSS-feed
function fetchRssFeed($rssUrl) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $rssUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_COOKIE, 'KOHA_INIT=1');
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $xmlData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'data' => $xmlData,
        'http_code' => $httpCode,
        'error' => $error
    ];
}

// Funktion för att processa RSS-feed till JSON-struktur
function processRssFeed($xml, $apiBaseUrl, $apiToken, $baseUrl = '') {
    $result = [
        'status' => 'ok',
        'cached_at' => date('Y-m-d H:i:s'),
        'channel' => [
            'title' => (string)$xml->channel->title,
            'link' => (string)$xml->channel->link,
            'description' => (string)$xml->channel->description,
            'language' => (string)$xml->channel->language,
            'lastBuildDate' => (string)$xml->channel->lastBuildDate
        ],
        'items' => []
    ];

    // Lägg till varje bok/item från RSS-feeden
    foreach ($xml->channel->item as $item) {
        $link = (string)$item->link;
        $biblioId = extractBiblioId($link);
        $bookData = [
            'isbn' => null,
            'title' => null,
            'author' => null
        ];

        if ($biblioId) {
            $bookData = getBookDataFromApi($biblioId, $apiBaseUrl, $apiToken);
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

        $result['items'][] = [
            'title' => (string)$item->title,
            'link' => $link,
            'description' => (string)$item->description,
            'pubDate' => (string)$item->pubDate,
            'guid' => (string)$item->guid,
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
            'image_url' => $imageUrl,
            'image_cached' => $cachedImagePath,
            'image_cached_url' => $cachedImageFullUrl
        ];
    }

    return $result;
}

// Funktion för att generera XML-output från result-array
function generateXmlOutput($result) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response></response>');

    // Lägg till status och cached_at
    $xml->addChild('status', htmlspecialchars($result['status']));
    $xml->addChild('cached_at', htmlspecialchars($result['cached_at']));

    // Lägg till channel-information
    $channel = $xml->addChild('channel');
    $channel->addChild('title', htmlspecialchars($result['channel']['title']));
    $channel->addChild('link', htmlspecialchars($result['channel']['link']));
    $channel->addChild('description', htmlspecialchars($result['channel']['description']));
    $channel->addChild('language', htmlspecialchars($result['channel']['language']));
    $channel->addChild('lastBuildDate', htmlspecialchars($result['channel']['lastBuildDate']));

    // Lägg till items
    $items = $xml->addChild('items');
    foreach ($result['items'] as $itemData) {
        $item = $items->addChild('item');

        foreach ($itemData as $key => $value) {
            if ($value !== null && $value !== '') {
                // Hantera speciella tecken
                $item->addChild($key, htmlspecialchars($value));
            } else {
                // Lägg till tom tag för null-värden
                $item->addChild($key);
            }
        }
    }

    // Formatera XML med indentation
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
}

// Funktion för att hämta item types från API
function getItemTypesFromApi($apiBaseUrl, $apiToken) {
    $ch = curl_init();

    // Bygg URL - API-endpoint för item_types
    $url = rtrim($apiBaseUrl, '/') . '/item_types';

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    // Returnera strukturerad respons med felhantering
    if ($httpCode !== 200 || !$response) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => $error ?: 'API returnerade inte status 200',
            'data' => []
        ];
    }

    // Parsa JSON-respons
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'http_code' => $httpCode,
            'error' => 'Kunde inte parsa JSON: ' . json_last_error_msg(),
            'data' => []
        ];
    }

    // Extrahera relevanta fält från varje item type
    $itemTypes = [];
    foreach ($data as $item) {
        $itemTypes[] = [
            'item_type_id' => $item['item_type_id'] ?? null,
            'description' => $item['description'] ?? null,
            'parent_type' => $item['parent_type'] ?? null,
            'image_url' => $item['image_url'] ?? null,
            'searchcategory' => $item['searchcategory'] ?? null,
            'hide_in_opac' => $item['hide_in_opac'] ?? false
        ];
    }

    return [
        'success' => true,
        'http_code' => $httpCode,
        'error' => null,
        'data' => $itemTypes
    ];
}
?>
