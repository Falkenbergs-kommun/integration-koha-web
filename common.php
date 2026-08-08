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
    // Snabb fail vid nätverksblockering (droppade paket) — påverkar bara connect-fasen
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

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

// Trimma MARC-punktuation (avslutande ; , / .)
function trimMarcPunctuation($str) {
    return trim(rtrim(trim($str), ';,/.'));
}

// Hämta MARC-post som decoded array (marc-in-json)
// Returnerar decoded MARC-array eller null vid fel
function fetchMarcRecord($biblioUrl, $apiToken) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $biblioUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/marc-in-json',
        'Authorization: Bearer ' . $apiToken
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return null;
    }

    $marc = json_decode($response, true);
    if (!isset($marc['fields']) || !is_array($marc['fields'])) {
        return null;
    }

    return $marc;
}

// Extrahera flera fält från en MARC-post (ren parsning, ingen I/O)
// Returnerar associativ array med: series_title, publication_year, publication_period,
// language_code, subjects, genre_form, sab_classification, contributors
function extractFieldsFromMarc(array $marc) {
    $result = [
        'series_title' => null,
        'publication_year' => null,
        'publication_period' => null,
        'language_code' => null,
        'subjects' => [],
        'genre_form' => [],
        'sab_classification' => null,
        'contributors' => [],
    ];

    // MARC 008: kontrollfält med fast längd (språkkod pos 35-37)
    // 008 är ett kontrollfält utan subfields, lagras som direkt sträng
    if (isset($marc['fields'])) {
        foreach ($marc['fields'] as $field) {
            if (isset($field['008']) && is_string($field['008'])) {
                $val = $field['008'];
                if (strlen($val) >= 38) {
                    $lang = substr($val, 35, 3);
                    if (preg_match('/^[a-z]{3}$/', $lang) && $lang !== '   ') {
                        $result['language_code'] = $lang;
                    }
                }
                break; // 008 förekommer bara en gång
            }
        }
    }

    $pubYear264 = null;
    $pubYear260 = null;
    $partName245p = null;
    $series = [];

    foreach ($marc['fields'] as $field) {
        // MARC 245$p: deltitel (part name) — kan innehålla bandets utgivningsår
        if (isset($field['245']['subfields']) && $partName245p === null) {
            foreach ($field['245']['subfields'] as $sf) {
                if (isset($sf['p'])) {
                    $partName245p = $sf['p'];
                    break;
                }
            }
        }

        // MARC 084$a: SAB-klassifikation (ta första förekomsten)
        if (isset($field['084']['subfields']) && $result['sab_classification'] === null) {
            foreach ($field['084']['subfields'] as $sf) {
                if (isset($sf['a'])) {
                    $result['sab_classification'] = trimMarcPunctuation($sf['a']);
                    break;
                }
            }
        }

        // MARC 264$c: utgivningsår (RDA-standard, föredras)
        if (isset($field['264']['subfields']) && $pubYear264 === null) {
            foreach ($field['264']['subfields'] as $sf) {
                if (isset($sf['c'])) {
                    $pubYear264 = trimMarcPunctuation($sf['c']);
                    break;
                }
            }
        }

        // MARC 260$c: utgivningsår (äldre format, fallback)
        if (isset($field['260']['subfields']) && $pubYear260 === null) {
            foreach ($field['260']['subfields'] as $sf) {
                if (isset($sf['c'])) {
                    $pubYear260 = trimMarcPunctuation($sf['c']);
                    break;
                }
            }
        }

        // MARC 490: serieinfo (subfields $a, $v, $x) — repeterbart
        if (isset($field['490']['subfields'])) {
            $name = null;
            $volume = null;
            $issn = null;
            foreach ($field['490']['subfields'] as $sf) {
                if (isset($sf['a'])) $name = trimMarcPunctuation($sf['a']);
                if (isset($sf['v'])) $volume = trimMarcPunctuation($sf['v']);
                if (isset($sf['x'])) $issn = trimMarcPunctuation($sf['x']);
            }
            if ($name !== null && $name !== '') {
                $entry = ['name' => $name];
                if ($volume !== null && $volume !== '') $entry['volume'] = $volume;
                if ($issn !== null && $issn !== '') $entry['issn'] = $issn;
                $series[] = $entry;
            }
        }

        // MARC 650$a: ämnesord (repeterbart)
        if (isset($field['650']['subfields'])) {
            foreach ($field['650']['subfields'] as $sf) {
                if (isset($sf['a'])) {
                    $val = trimMarcPunctuation($sf['a']);
                    if ($val !== '') $result['subjects'][] = $val;
                }
            }
        }

        // MARC 655$a: genre/form (repeterbart)
        if (isset($field['655']['subfields'])) {
            foreach ($field['655']['subfields'] as $sf) {
                if (isset($sf['a'])) {
                    $val = trimMarcPunctuation($sf['a']);
                    if ($val !== '') $result['genre_form'][] = $val;
                }
            }
        }

        // MARC 700$a: medverkande (repeterbart)
        if (isset($field['700']['subfields'])) {
            foreach ($field['700']['subfields'] as $sf) {
                if (isset($sf['a'])) {
                    $val = trimMarcPunctuation($sf['a']);
                    if ($val !== '') $result['contributors'][] = $val;
                }
            }
        }
    }

    // Utgivningsår: föredra bandets år (245$p) → 264$c (RDA) → 260$c
    $rawYear = $pubYear264 ?? $pubYear260;

    // Spara utgivningsperiod om 260$c/264$c innehåller ett datumintervall (t.ex. "1967-1991")
    if ($rawYear !== null && preg_match('/((?:19|20)\d{2})\s*-\s*((?:19|20)\d{2})/', $rawYear, $periodMatch)) {
        $result['publication_period'] = $periodMatch[1] . '-' . $periodMatch[2];
    }

    // 245$p som innehåller ett fristående årtal (t.ex. "1987 : ") anger bandets utgivningsår
    $partYearFound = false;
    if ($partName245p !== null && preg_match('/^\s*((?:19|20)\d{2})\s*[;:,.\/ ]*$/', $partName245p, $pm)) {
        $result['publication_year'] = $pm[1];
        $partYearFound = true;
    }

    // Fallback: första fyrsiffriga årtalet från 264$c/260$c
    if (!$partYearFound && $rawYear !== null && preg_match('/(19|20)\d{2}/', $rawYear, $m)) {
        $result['publication_year'] = $m[0];
    }

    // Serietitel
    $result['series_title'] = count($series) > 0 ? $series : null;

    // Dedupliera arrayer
    $result['subjects'] = array_values(array_unique($result['subjects']));
    $result['genre_form'] = array_values(array_unique($result['genre_form']));
    $result['contributors'] = array_values(array_unique($result['contributors']));

    // Returnera tomma arrayer som null för konsistens
    if (empty($result['subjects'])) $result['subjects'] = null;
    if (empty($result['genre_form'])) $result['genre_form'] = null;
    if (empty($result['contributors'])) $result['contributors'] = null;

    return $result;
}

// Hämta serieinfo från MARC 490 (subfields $a, $v, $x)
// Returnerar array av objekt: [{"name": "...", "volume": "...", "issn": "..."}] eller null
// Bakåtkompatibel wrapper — anropas av getBookDataFromApi() och backfill_series_title.php
function getSeriesFromMarc($biblioUrl, $apiToken) {
    $marc = fetchMarcRecord($biblioUrl, $apiToken);
    if ($marc === null) {
        return null;
    }
    $fields = extractFieldsFromMarc($marc);
    return $fields['series_title'] ?? null;
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

        // Hämta serieinfo från MARC 490 (strukturerade objekt med name/volume/issn)
        $seriesTitle = getSeriesFromMarc($apiBaseUrl . $biblioId, $apiToken);

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
            'series_title' => $seriesTitle,
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

    $imageDir = __DIR__ . '/public/images';
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

// Härled Kohas bashost (scheme://host[:port]) från API_BASE_URL.
// API_BASE_URL pekar på .../api/v1/biblios/ — opac-image.pl ligger däremot
// under /cgi-bin/koha/ på samma värd.
function kohaHostFromApiUrl($apiBaseUrl) {
    $parts = parse_url($apiBaseUrl ?: '');
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $host = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $host .= ':' . $parts['port'];
    }
    return $host;
}

// Hämta Kohas lokalt uppladdade omslag för ett biblio (används som fallback
// när ISBN saknas, t.ex. för bokcirkelkassar). Returnerar relativ web-path
// 'images/bib-{id}.jpg' eller null.
function fetchKohaLocalCover($biblioId, $kohaHost) {
    if (!$biblioId || !$kohaHost) {
        return null;
    }

    $imageDir = __DIR__ . '/public/images';
    $imageFilename = 'bib-' . $biblioId . '.jpg';
    $imagePath = $imageDir . '/' . $imageFilename;
    $imageWebPath = 'images/' . $imageFilename;

    if (file_exists($imagePath)) {
        return $imageWebPath;
    }

    if (!is_dir($imageDir)) {
        mkdir($imageDir, 0755, true);
    }

    $url = rtrim($kohaHost, '/') . '/cgi-bin/koha/opac-image.pl?biblionumber=' . urlencode($biblioId) . '&thumbnail=1';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    // Snabb fail vid nätverksblockering (droppade paket) — påverkar bara connect-fasen
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $imageData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    // Koha levererar en 43-byte 1x1 transparent GIF som placeholder när inget
    // lokalt omslag finns. Riktiga uppladdade omslag är alltid PNG eller JPEG.
    // Filtrera GIF-svar som "inget hittat" för att slippa cacha placeholders.
    $isPlaceholderGif = stripos($contentType, 'image/gif') !== false;
    if ($httpCode === 200 && $imageData && strpos($contentType, 'image') !== false && !$isPlaceholderGif) {
        if (file_put_contents($imagePath, $imageData)) {
            return $imageWebPath;
        }
    }

    return null;
}

// Resolver för bildtrippeln (image_url, image_cached, image_cached_url).
// Vid ISBN: kör Syndetics-vägen. Annars: försök Kohas lokala omslag.
// Returnerar trippel av null om ingen bild kan hittas.
function resolveBookImage($firstIsbn, $biblioId, $apiBaseUrl, $publicBaseUrl = '') {
    $imageUrl = null;
    $cachedImagePath = null;

    if ($firstIsbn) {
        $imageUrl = getImageUrl($firstIsbn);
        if ($imageUrl) {
            $cachedImagePath = cacheImage($firstIsbn, $imageUrl);
        }
    } elseif ($biblioId) {
        $kohaHost = kohaHostFromApiUrl($apiBaseUrl);
        if ($kohaHost) {
            $cachedImagePath = fetchKohaLocalCover($biblioId, $kohaHost);
            if ($cachedImagePath) {
                $imageUrl = rtrim($kohaHost, '/') . '/cgi-bin/koha/opac-image.pl?biblionumber=' . urlencode($biblioId) . '&thumbnail=1';
            }
        }
    }

    $cachedImageFullUrl = ($cachedImagePath && $publicBaseUrl) ? $publicBaseUrl . $cachedImagePath : null;

    return [
        'image_url' => $imageUrl,
        'image_cached' => $cachedImagePath,
        'image_cached_url' => $cachedImageFullUrl,
    ];
}

// Funktion för att hämta RSS-feed
function fetchRssFeed($rssUrl) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $rssUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    // Snabb fail vid nätverksblockering (droppade paket) — påverkar bara connect-fasen
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
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

        // Extrahera första ISBN och resolva bildtrippel (Syndetics eller Kohas lokala omslag)
        $firstIsbn = getFirstIsbn($bookData['isbn']);
        $image = resolveBookImage($firstIsbn, $biblioId, $apiBaseUrl, $baseUrl);

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
            'image_url' => $image['image_url'],
            'image_cached' => $image['image_cached'],
            'image_cached_url' => $image['image_cached_url']
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
            // Array-fält (t.ex. series_title) serialiseras som JSON — htmlspecialchars
            // på array är fatal TypeError i PHP 8
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
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
    // API_BASE_URL pekar till /biblios/, men item_types är på /api/v1/ nivå
    // Extrahera base URL genom att ta bort /biblios/ delen
    $baseApiUrl = preg_replace('#/biblios/?$#', '', $apiBaseUrl);
    $url = rtrim($baseApiUrl, '/') . '/item_types';

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

/**
 * Hämta biblios via items-endpoint med filtrering på item_type_id
 * Returnerar unika biblios sorterade efter biblio_id (fallande)
 *
 * @param string $apiBaseUrl Base URL för API (pekar till /biblios/)
 * @param string $apiToken OAuth bearer token
 * @param array $itemTypes Lista med item_type_id att filtrera på (OR-logik)
 * @param int $limit Max antal biblios att returnera
 * @return array Lista med biblio-objekt i samma format som GET /biblios
 */
/**
 * Safely truncate string to max length (multibyte-safe)
 *
 * @param mixed $value Value to truncate
 * @param int $maxLength Maximum allowed length
 * @return string|null Truncated string or null
 */
function safeTruncate($value, $maxLength)
{
    if ($value === null) {
        return null;
    }
    $value = (string)$value;
    if (mb_strlen($value) > $maxLength) {
        return mb_substr($value, 0, $maxLength);
    }
    return $value;
}

// ============================================================
// Directus-metadata och snapshot-fallback för webbendpoints
// ============================================================

// Hämta bokmetadata i bulk från Directus (kft_koha_biblios) för en lista biblio_ids.
// Returnerar ['success' => bool, 'error' => string|null, 'books' => [biblio_id => bokdata]]
// där bokdata har samma struktur som getBookDataFromApi().
function getBiblioMetadataFromDirectus(array $biblioIds) {
    $directusUrl = getenv('DIRECTUS_API_URL');
    $token = getenv('DIRECTUS_API_TOKEN');

    if (!$directusUrl || !$token) {
        return ['success' => false, 'error' => 'Directus ej konfigurerat (DIRECTUS_API_URL/DIRECTUS_API_TOKEN saknas)', 'books' => []];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $biblioIds))));
    if (empty($ids)) {
        return ['success' => true, 'error' => null, 'books' => []];
    }

    $fields = 'biblio_id,isbn,isbn_clean,title,author,abstract,subtitle,publisher,'
            . 'publication_year,publication_place,pages,material_size,edition_statement,'
            . 'series_title,age_restriction,url,ean,issn,notes';

    $books = [];
    // Chunka för att undvika för långa URL:er vid stora hyllor
    foreach (array_chunk($ids, 100) as $chunk) {
        $filter = json_encode([
            '_and' => [
                ['biblio_id' => ['_in' => $chunk]],
                ['status' => ['_eq' => 'active']]
            ]
        ]);
        $url = rtrim($directusUrl, '/') . '/items/kft_koha_biblios?limit=-1'
             . '&fields=' . $fields
             . '&filter=' . urlencode($filter);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return ['success' => false, 'error' => $error ?: ('HTTP ' . $httpCode), 'books' => []];
        }

        $data = json_decode($response, true);
        if (!isset($data['data']) || !is_array($data['data'])) {
            return ['success' => false, 'error' => 'Oväntat svar från Directus', 'books' => []];
        }

        foreach ($data['data'] as $row) {
            // Strängnyckel för att matcha extractBiblioId() som returnerar strängar
            $books[(string)$row['biblio_id']] = mapDirectusRowToBookData($row);
        }
    }

    return ['success' => true, 'error' => null, 'books' => $books];
}

// Mappa en Directus-rad (kft_koha_biblios) till samma struktur som getBookDataFromApi()
function mapDirectusRowToBookData(array $row) {
    // series_title lagras som JSON-array i Directus men kan komma tillbaka
    // som array eller JSON-sträng beroende på fältkonfiguration
    $series = $row['series_title'] ?? null;
    if (is_string($series)) {
        $decoded = json_decode($series, true);
        $series = is_array($decoded) ? $decoded : null;
    }
    if (is_array($series) && count($series) === 0) {
        $series = null;
    }

    // Tomma strängar → null för paritet med Koha API
    $val = function($key) use ($row) {
        $v = $row[$key] ?? null;
        return ($v === '') ? null : $v;
    };

    return [
        'isbn' => $val('isbn'),
        'title' => $val('title'),
        'author' => $val('author'),
        'abstract' => $val('abstract'),
        'subtitle' => $val('subtitle'),
        'publisher' => $val('publisher'),
        'publication_year' => $val('publication_year'),
        'publication_place' => $val('publication_place'),
        'pages' => $val('pages'),
        'material_size' => $val('material_size'),
        'edition_statement' => $val('edition_statement'),
        'series_title' => $series,
        'age_restriction' => $val('age_restriction'),
        'url' => $val('url'),
        'ean' => $val('ean'),
        'issn' => $val('issn'),
        'notes' => $val('notes')
    ];
}

// Intern hjälpare: GET mot Directus items-API. Returnerar
// ['success' => bool, 'error' => string|null, 'rows' => array]
function directusItemsGet($collection, array $queryParams) {
    $directusUrl = getenv('DIRECTUS_API_URL');
    $token = getenv('DIRECTUS_API_TOKEN');

    if (!$directusUrl || !$token) {
        return ['success' => false, 'error' => 'Directus ej konfigurerat (DIRECTUS_API_URL/DIRECTUS_API_TOKEN saknas)', 'rows' => []];
    }

    $url = rtrim($directusUrl, '/') . '/items/' . $collection . '?' . http_build_query($queryParams);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return ['success' => false, 'error' => $error ?: ('HTTP ' . $httpCode), 'rows' => []];
    }

    $data = json_decode($response, true);
    if (!isset($data['data']) || !is_array($data['data'])) {
        return ['success' => false, 'error' => 'Oväntat svar från Directus', 'rows' => []];
    }

    return ['success' => true, 'error' => null, 'rows' => $data['data']];
}

// Hämta de senaste aktiva biblio_id:na från Directus (kft_koha_biblios),
// sorterat på biblio_id fallande. Ersätter Kohas /biblios?_order_by=-biblio_id.
// Returnerar ['success' => bool, 'error' => string|null, 'ids' => int[]]
function getLatestBiblioIdsFromDirectus($limit) {
    $res = directusItemsGet('kft_koha_biblios', [
        'fields' => 'biblio_id',
        'sort' => '-biblio_id',
        'limit' => intval($limit),
        'filter' => json_encode(['status' => ['_eq' => 'active']])
    ]);
    if (!$res['success']) {
        return ['success' => false, 'error' => $res['error'], 'ids' => []];
    }
    $ids = array_values(array_filter(array_map(function($row) {
        return intval($row['biblio_id'] ?? 0);
    }, $res['rows'])));
    return ['success' => true, 'error' => null, 'ids' => $ids];
}

// Hämta distinkta biblio_ids (senaste först) från kft_koha_items filtrerat på
// item_type_id, location och/eller collection_code (OR inom fält, AND mellan).
// Ersätter getFilteredBibliosFromItems() utan Koha-beroende. Sorteringen
// -biblio_id lägger alla exemplar av samma biblio intill varandra, så
// dedupliceringen kan avbryta så fort en ny biblio dyker upp efter limit —
// hela träffmängden behöver aldrig hållas i minnet.
// Returnerar ['success', 'error', 'ids' => int[], 'item_types_map' => [biblio_id => [typ, ...]]]
function getFilteredBiblioIdsFromDirectus(array $itemTypes, array $locations, array $ccodes, $limit) {
    $and = [['status' => ['_eq' => 'active']]];
    if (!empty($itemTypes)) {
        $and[] = ['item_type_id' => ['_in' => array_values($itemTypes)]];
    }
    if (!empty($locations)) {
        $and[] = ['location' => ['_in' => array_values($locations)]];
    }
    if (!empty($ccodes)) {
        $and[] = ['collection_code' => ['_in' => array_values($ccodes)]];
    }
    $filter = json_encode(['_and' => $and]);

    $ids = [];
    $itemTypesMap = [];
    $pageSize = 500;
    $maxPages = 20; // Skyddstak: 10k items räcker gott för limit ≤ 50

    for ($page = 0; $page < $maxPages; $page++) {
        $res = directusItemsGet('kft_koha_items', [
            'fields' => 'biblio_id,item_type_id',
            'sort' => '-biblio_id,item_id',
            'limit' => $pageSize,
            'offset' => $page * $pageSize,
            'filter' => $filter
        ]);
        if (!$res['success']) {
            return ['success' => false, 'error' => $res['error'], 'ids' => [], 'item_types_map' => []];
        }

        foreach ($res['rows'] as $row) {
            $biblioId = intval($row['biblio_id'] ?? 0);
            if (!$biblioId) {
                continue;
            }
            if (!isset($itemTypesMap[$biblioId])) {
                if (count($ids) >= $limit) {
                    // Ny biblio efter nådd limit — klart (exemplar ligger adjacenta)
                    return ['success' => true, 'error' => null, 'ids' => $ids, 'item_types_map' => $itemTypesMap];
                }
                $itemTypesMap[$biblioId] = [];
                $ids[] = $biblioId;
            }
            $type = $row['item_type_id'] ?? null;
            if ($type && !in_array($type, $itemTypesMap[$biblioId])) {
                $itemTypesMap[$biblioId][] = $type;
            }
        }

        if (count($res['rows']) < $pageSize) {
            break; // Sista sidan
        }
    }

    return ['success' => true, 'error' => null, 'ids' => $ids, 'item_types_map' => $itemTypesMap];
}

// Som resolveBookImage() men gör ALDRIG anrop mot Koha — endast lokal
// bildcache och Syndetics. Kohas lokala omslag (bib-{id}.jpg) används
// bara om filen redan finns på disk.
function resolveBookImageLocal($firstIsbn, $biblioId, $publicBaseUrl = '', $allowSyndetics = true) {
    $imageUrl = null;
    $cachedImagePath = null;

    if ($firstIsbn) {
        $imageUrl = getImageUrl($firstIsbn);
        if (file_exists(__DIR__ . '/public/images/' . $firstIsbn . '.jpg')) {
            $cachedImagePath = 'images/' . $firstIsbn . '.jpg';
        } elseif ($allowSyndetics && $imageUrl) {
            $cachedImagePath = cacheImage($firstIsbn, $imageUrl);
        }
    } elseif ($biblioId) {
        if (file_exists(__DIR__ . '/public/images/bib-' . $biblioId . '.jpg')) {
            $cachedImagePath = 'images/bib-' . $biblioId . '.jpg';
        }
    }

    $cachedImageFullUrl = ($cachedImagePath && $publicBaseUrl) ? $publicBaseUrl . $cachedImagePath : null;

    return [
        'image_url' => $imageUrl,
        'image_cached' => $cachedImagePath,
        'image_cached_url' => $cachedImageFullUrl,
    ];
}

// Som processRssFeed() men hämtar metadata i bulk från Directus i stället för
// Koha-API — 1 Koha-request (RSS:en) i stället för 2+ per bok.
// Returnerar ['result' => resultatarray, 'directus_ok' => bool]
function processRssFeedFromDirectus($xml, $baseUrl = '') {
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

    // Pass 1: samla RSS-fält och biblio_ids
    $rssItems = [];
    foreach ($xml->channel->item as $item) {
        $link = (string)$item->link;
        $rssItems[] = [
            'title' => (string)$item->title,
            'link' => $link,
            'description' => (string)$item->description,
            'pubDate' => (string)$item->pubDate,
            'guid' => (string)$item->guid,
            'biblio_id' => extractBiblioId($link)
        ];
    }

    // Ett bulk-anrop för alla böcker i hyllan
    $directus = getBiblioMetadataFromDirectus(array_filter(array_column($rssItems, 'biblio_id')));

    $emptyBookData = [
        'isbn' => null, 'title' => null, 'author' => null, 'abstract' => null,
        'subtitle' => null, 'publisher' => null, 'publication_year' => null,
        'publication_place' => null, 'pages' => null, 'material_size' => null,
        'edition_statement' => null, 'series_title' => null, 'age_restriction' => null,
        'url' => null, 'ean' => null, 'issn' => null, 'notes' => null
    ];

    // Pass 2: bygg items med exakt samma struktur som processRssFeed()
    foreach ($rssItems as $rssItem) {
        $biblioId = $rssItem['biblio_id'];
        $bookData = ($biblioId !== null && isset($directus['books'][$biblioId]))
            ? $directus['books'][$biblioId]
            : $emptyBookData;

        $firstIsbn = getFirstIsbn($bookData['isbn']);
        $image = resolveBookImageLocal($firstIsbn, $biblioId, $baseUrl);

        $result['items'][] = [
            'title' => $rssItem['title'],
            'link' => $rssItem['link'],
            'description' => $rssItem['description'],
            'pubDate' => $rssItem['pubDate'],
            'guid' => $rssItem['guid'],
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
            'image_cached_url' => $image['image_cached_url']
        ];
    }

    return ['result' => $result, 'directus_ok' => $directus['success']];
}

// Skriv last-known-good-snapshot — endast vid lyckad hämtning med minst 1 item.
// Skyddar mot att tomma 200-svar från Koha skriver över en bra snapshot.
function saveSnapshot($snapshotFile, $content, $itemCount) {
    if ($itemCount < 1) {
        return false;
    }
    return file_put_contents($snapshotFile, $content) !== false;
}

// Läs snapshot och markera som stale. Returnerar innehåll som sträng, eller null
// om snapshot saknas, är korrupt eller saknar items. Original cached_at behålls
// så att konsumenter ser datans verkliga ålder.
function loadStaleSnapshot($snapshotFile, $format) {
    if (!file_exists($snapshotFile)) {
        return null;
    }
    $content = file_get_contents($snapshotFile);
    if (!$content) {
        return null;
    }

    if ($format === 'xml') {
        if (strpos($content, '<items/>') !== false || strpos($content, '</cached_at>') === false) {
            return null;
        }
        return str_replace('</cached_at>', "</cached_at>\n  <stale>true</stale>", $content);
    }

    $data = json_decode($content, true);
    if (!is_array($data) || empty($data['items'])) {
        return null;
    }
    $data['stale'] = true;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// Servera snapshot vid uppströmsfel (HTTP 200 + stale-markering); finns ingen
// användbar snapshot skickas ordinarie felrespons. Avslutar alltid requesten.
function serveSnapshotOrError($snapshotFile, $format, array $errorPayload) {
    $stale = loadStaleSnapshot($snapshotFile, $format);
    if ($stale !== null) {
        http_response_code(200);
        echo $stale;
        exit;
    }
    http_response_code(500);
    echo $format === 'xml' ? generateErrorXml($errorPayload) : json_encode($errorPayload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Generera enkel fel-XML (delas av shelf.php och latest.php)
function generateErrorXml($error) {
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><response></response>');
    $xml->addChild('status', htmlspecialchars($error['status']));
    $xml->addChild('message', htmlspecialchars($error['message']));

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML($xml->asXML());

    return $dom->saveXML();
}

// Fail-throttle: begränsa Koha-försök under pågående utfall/IP-bann.
// En flaggfil per resurs; färsk flagga = hoppa över Koha och gå direkt på snapshot.
// Default 30 min — konfigurerbar via KOHA_FAIL_THROTTLE i .env (sekunder).
function recentFailureExists($flagFile, $seconds = null) {
    if ($seconds === null) {
        $seconds = intval(getenv('KOHA_FAIL_THROTTLE') ?: 1800);
    }
    return file_exists($flagFile) && (time() - filemtime($flagFile)) < $seconds;
}

function markFailure($flagFile) {
    @touch($flagFile);
}
?>
