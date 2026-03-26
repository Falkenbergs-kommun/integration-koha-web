#!/usr/bin/env php
<?php
/**
 * Förbered embedding-text för biblioteksböcker
 *
 * Hämtar data från alla Directus-kollektioner (biblios, enriched, items, hold_counts)
 * och sammanställer en strukturerad text per biblio som är optimerad för vektorsökning.
 *
 * Användning:
 *   php prepare_embedding_text.php [--limit=N] [--biblio-id=N] [--output=json|jsonl|csv] [--file=OUTPUT_FILE] [-v|--verbose]
 *
 * Exempel:
 *   php prepare_embedding_text.php --limit=10 -v
 *   php prepare_embedding_text.php --biblio-id=71069
 *   php prepare_embedding_text.php --output=jsonl --file=embeddings_input.jsonl
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Embedding Preparation
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

// ─── Mappning av item_type_id → svenska mediatyper ──────────────────

$ITEM_TYPE_LABELS = [
    'BOK'       => 'Bok',
    'EBOK'      => 'E-bok',
    'LJUDBOK'   => 'Ljudbok',
    'DBOK'      => 'Daisybok',
    'BARNBOK'   => 'Barnbok',
    'BARNCD'    => 'Barn-CD',
    'BARNDVD'   => 'Barn-DVD',
    'BARNSPEL'  => 'Barnspel',
    'CD'        => 'CD-skiva',
    'DVD'       => 'DVD-film',
    'BLURAY'    => 'Blu-ray',
    'TIDSKRIFT' => 'Tidskrift',
    'SERIAL'    => 'Seriepublikation',
    'DAGSTID'   => 'Dagstidning',
    'NOTA'      => 'Musikalier/noter',
    'SPEL'      => 'Spel',
    'PUNKT'     => 'Punktskrift',
    'KARTA'     => 'Karta',
    'MIKROFILM' => 'Mikrofilm',
    'FILM'      => 'Film',
    'MUSIK'     => 'Musik',
    'SPRAKKURS' => 'Språkkurs',
    'REFBOK'    => 'Referensbok',
    'DATABAS'   => 'Databas',
    'DEPBOK'    => 'Depositionsbok',
    'MÅNGMEDIA' => 'Mångmedia',
];

// ─── Directus-paginering ─────────────────────────────────────────────

/**
 * Hämta alla poster från en Directus-kollektion med paginering
 *
 * @param DirectusClient $client
 * @param string $collection Kollektionsnamn
 * @param string $fields Kommaseparerade fält
 * @param array $filter Valfritt Directus-filter
 * @param bool $verbose
 * @return array Alla poster
 */
function fetchAllFromCollection($client, $collection, $fields = '*', $filter = [], $verbose = false)
{
    $all = [];
    $offset = 0;
    $limit = 2000;

    while (true) {
        $params = [
            'fields' => $fields,
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'id'
        ];

        if (!empty($filter)) {
            $params['filter'] = json_encode($filter);
        }

        $query = http_build_query($params);
        $fullUrl = rtrim($client->getBaseUrl(), '/') . "/items/{$collection}?{$query}";

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $client->getToken(),
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Misslyckades hämta {$collection} offset {$offset} (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        $items = $data['data'] ?? [];

        if (empty($items)) {
            break;
        }

        $all = array_merge($all, $items);

        if ($verbose && count($all) % 5000 === 0) {
            echo "  {$collection}: hämtat " . count($all) . " poster...\n";
        }

        if (count($items) < $limit) {
            break;
        }

        $offset += $limit;
    }

    return $all;
}

// ─── Aggregera item-data per biblio ──────────────────────────────────

/**
 * Aggregerar items till strukturerad info per biblio_id
 *
 * @param array $items Alla items från kft_koha_items
 * @return array Map av biblio_id => aggregerad info
 */
function aggregateItemsByBiblio($items)
{
    $aggregated = [];

    foreach ($items as $item) {
        $biblioId = $item['biblio_id'] ?? null;
        if ($biblioId === null || ($item['status'] ?? '') !== 'active') {
            continue;
        }

        if (!isset($aggregated[$biblioId])) {
            $aggregated[$biblioId] = [
                'total_items' => 0,
                'available_items' => 0,
                'item_types' => [],
                'collection_codes' => [],
                'locations' => [],
                'branches' => [],
                'callnumbers' => [],
            ];
        }

        $agg = &$aggregated[$biblioId];
        $agg['total_items']++;

        // Tillgänglig = ej utlånad, ej skadad, ej borttappad, ej makulerad, ej referens
        $isAvailable = empty($item['checked_out_date'])
            && ($item['not_for_loan_status'] ?? 0) == 0
            && ($item['damaged_status'] ?? 0) == 0
            && ($item['lost_status'] ?? 0) == 0
            && ($item['withdrawn'] ?? 0) == 0;

        if ($isAvailable) {
            $agg['available_items']++;
        }

        // Samla unika mediatyper
        $itemType = $item['effective_item_type_id'] ?? $item['item_type_id'] ?? null;
        if ($itemType !== null) {
            $agg['item_types'][$itemType] = ($agg['item_types'][$itemType] ?? 0) + 1;
        }

        // Samlingskoder
        $collCode = $item['collection_code'] ?? null;
        if ($collCode !== null) {
            $agg['collection_codes'][$collCode] = ($agg['collection_codes'][$collCode] ?? 0) + 1;
        }

        // Placering
        $location = $item['location'] ?? $item['permanent_location'] ?? null;
        if ($location !== null) {
            $agg['locations'][$location] = true;
        }

        // Filialer
        $branch = $item['home_library_id'] ?? null;
        if ($branch !== null) {
            $agg['branches'][$branch] = true;
        }

        // Hyllsignum (använd första)
        $callnumber = $item['callnumber'] ?? null;
        if ($callnumber !== null && !isset($agg['callnumbers'][$callnumber])) {
            $agg['callnumbers'][$callnumber] = true;
        }

        unset($agg);
    }

    return $aggregated;
}

// ─── Bygg embedding-text ─────────────────────────────────────────────

/**
 * Bygg en strukturerad text optimerad för embedding
 *
 * @param array $biblio Biblio-post från kft_koha_biblios
 * @param array|null $enriched Enriched-post (eller null)
 * @param array|null $itemAgg Aggregerad item-data (eller null)
 * @param array|null $holdData Hold-data från kft_koha_hold_counts (eller null)
 * @param array $itemTypeLabels Mappning item_type_id → svensk etikett
 * @return string Embedding-texten
 */
function buildEmbeddingText($biblio, $enriched, $itemAgg, $holdData, $itemTypeLabels)
{
    $parts = [];

    // ── Titel och författare ──
    $title = trim($biblio['title'] ?? '');
    $subtitle = trim($biblio['subtitle'] ?? '');
    $author = trim($biblio['author'] ?? '');

    $titleLine = $title;
    if ($subtitle) {
        $titleLine .= ': ' . $subtitle;
    }
    if ($author) {
        $titleLine .= ' av ' . $author;
    }
    $parts[] = $titleLine;

    // ── Mediatyp ──
    if ($itemAgg && !empty($itemAgg['item_types'])) {
        $typeLabels = [];
        foreach ($itemAgg['item_types'] as $typeId => $count) {
            $label = $itemTypeLabels[strtoupper($typeId)] ?? $typeId;
            $typeLabels[] = $label;
        }
        $parts[] = 'Mediatyp: ' . implode(', ', $typeLabels);
    }

    // ── Serie (JSON-array eller legacy-sträng) ──
    $seriesRaw = $biblio['series_title'] ?? null;
    if ($seriesRaw !== null) {
        $seriesArr = is_array($seriesRaw) ? $seriesRaw : (is_string($seriesRaw) ? json_decode($seriesRaw, true) : null);
        if (!is_array($seriesArr) && is_string($seriesRaw) && $seriesRaw !== '') {
            $seriesArr = [$seriesRaw];
        }
        if (!empty($seriesArr)) {
            $parts[] = 'Serie: ' . implode(', ', $seriesArr);
        }
    }

    // ── Abstract (enriched prioriteras) ──
    $abstract = null;
    if ($enriched && !empty($enriched['abstract_enriched'])) {
        $abstract = trim($enriched['abstract_enriched']);
    } elseif (!empty($biblio['abstract'])) {
        $abstract = trim($biblio['abstract']);
    }
    if ($abstract) {
        $parts[] = $abstract;
    }

    // ── Ämnen och taggar (från enriched) ──
    if ($enriched) {
        $subjects = $enriched['subjects'] ?? null;
        if (is_string($subjects)) {
            $subjects = json_decode($subjects, true);
        }
        if (is_array($subjects) && !empty($subjects)) {
            $parts[] = 'Ämnen: ' . implode(', ', $subjects);
        }

        $tags = $enriched['tags'] ?? null;
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }
        if (is_array($tags) && !empty($tags)) {
            $parts[] = 'Taggar: ' . implode(', ', $tags);
        }

        $audience = trim($enriched['target_audience'] ?? '');
        if ($audience) {
            $parts[] = 'Målgrupp: ' . $audience;
        }
    }

    // ── Förlag och publicering ──
    $pubParts = [];
    $publisher = trim($biblio['publisher'] ?? '');
    if ($publisher) {
        $pubParts[] = $publisher;
    }
    $pubYear = trim($biblio['publication_year'] ?? '');
    if ($pubYear) {
        $pubParts[] = $pubYear;
    }
    $pubPlace = trim($biblio['publication_place'] ?? '');
    if ($pubPlace) {
        $pubParts[] = $pubPlace;
    }
    if (!empty($pubParts)) {
        $parts[] = 'Utgivning: ' . implode(', ', $pubParts);
    }

    // ── Fysisk beskrivning ──
    $pages = trim($biblio['pages'] ?? '');
    if ($pages) {
        $parts[] = 'Omfång: ' . $pages;
    }

    // ── Åldersrestriktion ──
    $ageRestriction = trim($biblio['age_restriction'] ?? '');
    if ($ageRestriction) {
        $parts[] = 'Åldersgräns: ' . $ageRestriction;
    }

    // ── Hyllsignum / klassifikation ──
    if ($itemAgg && !empty($itemAgg['callnumbers'])) {
        $callnumbers = array_keys($itemAgg['callnumbers']);
        // Visa max 3 unika hyllsignum
        $parts[] = 'Klassifikation: ' . implode(', ', array_slice($callnumbers, 0, 3));
    }

    // ── Samlingskoder ──
    if ($itemAgg && !empty($itemAgg['collection_codes'])) {
        $codes = array_keys($itemAgg['collection_codes']);
        $parts[] = 'Samling: ' . implode(', ', $codes);
    }

    // ── Placering/filialer ──
    if ($itemAgg && !empty($itemAgg['branches'])) {
        $branches = array_keys($itemAgg['branches']);
        $parts[] = 'Finns på: ' . implode(', ', $branches);
    }

    // ── Exemplar och tillgänglighet ──
    if ($itemAgg) {
        $parts[] = "Exemplar: {$itemAgg['total_items']} totalt, {$itemAgg['available_items']} tillgängliga";
    }

    // ── Köande/reservationer ──
    if ($holdData && ($holdData['total_holds'] ?? 0) > 0) {
        $holds = $holdData['total_holds'];
        $itemCount = $holdData['item_count'] ?? ($itemAgg['total_items'] ?? 0);
        $ratio = $itemCount > 0 ? round($holds / $itemCount, 1) : $holds;
        $parts[] = "Reservationer: {$holds} köande" . ($itemCount > 0 ? " ({$ratio} per exemplar)" : '');
    }

    // ── Anteckningar ──
    $notes = trim($biblio['notes'] ?? '');
    if ($notes && mb_strlen($notes) > 5) {
        // Begränsa notes till 300 tecken för att inte dominera embedding
        $notes = mb_substr($notes, 0, 300);
        $parts[] = 'Anmärkning: ' . $notes;
    }

    // ── ISBN ──
    $isbn = trim($biblio['isbn_clean'] ?? $biblio['isbn'] ?? '');
    if ($isbn) {
        $parts[] = 'ISBN: ' . $isbn;
    }

    return implode('. ', $parts);
}

/**
 * Bygg ett strukturerat metadataobjekt för en biblio
 *
 * @param array $biblio Biblio-post
 * @param array|null $enriched Enriched-post
 * @param array|null $itemAgg Aggregerad item-data
 * @param array|null $holdData Hold-data
 * @param array $itemTypeLabels
 * @return array Strukturerad metadata
 */
function buildMetadata($biblio, $enriched, $itemAgg, $holdData, $itemTypeLabels)
{
    $meta = [
        'biblio_id' => $biblio['biblio_id'],
        'title' => $biblio['title'] ?? null,
        'author' => $biblio['author'] ?? null,
        'isbn' => $biblio['isbn_clean'] ?? null,
        'publication_year' => $biblio['publication_year'] ?? null,
    ];

    // Mediatyper
    if ($itemAgg && !empty($itemAgg['item_types'])) {
        $types = [];
        foreach ($itemAgg['item_types'] as $typeId => $count) {
            $types[] = $itemTypeLabels[strtoupper($typeId)] ?? $typeId;
        }
        $meta['media_types'] = $types;
    }

    // Enriched-data
    if ($enriched) {
        $meta['target_audience'] = $enriched['target_audience'] ?? null;
        $subjects = $enriched['subjects'] ?? null;
        if (is_string($subjects)) {
            $subjects = json_decode($subjects, true);
        }
        $meta['subjects'] = is_array($subjects) ? $subjects : null;
    }

    // Tillgänglighet
    if ($itemAgg) {
        $meta['total_items'] = $itemAgg['total_items'];
        $meta['available_items'] = $itemAgg['available_items'];
        $meta['branches'] = array_keys($itemAgg['branches'] ?? []);
    }

    // Reservationer
    if ($holdData) {
        $meta['total_holds'] = $holdData['total_holds'] ?? 0;
    }

    // Utökad metadata för Qdrant-payload
    $meta['subtitle'] = $biblio['subtitle'] ?? null;
    $meta['publisher'] = $biblio['publisher'] ?? null;
    $meta['publication_place'] = $biblio['publication_place'] ?? null;
    // series_title: säkerställ array för Qdrant keyword-index
    $stRaw = $biblio['series_title'] ?? null;
    if ($stRaw !== null) {
        $stArr = is_array($stRaw) ? $stRaw : (is_string($stRaw) ? json_decode($stRaw, true) : null);
        if (!is_array($stArr) && is_string($stRaw) && $stRaw !== '') {
            $stArr = [$stRaw];
        }
        $meta['series_title'] = !empty($stArr) ? $stArr : null;
    } else {
        $meta['series_title'] = null;
    }
    $meta['age_restriction'] = $biblio['age_restriction'] ?? null;
    $meta['ean'] = $biblio['ean'] ?? null;
    $meta['has_enriched_abstract'] = ($enriched && !empty($enriched['abstract_enriched']));

    // Taggar från enriched-data
    if ($enriched) {
        $tags = $enriched['tags'] ?? null;
        if (is_string($tags)) {
            $tags = json_decode($tags, true);
        }
        $meta['tags'] = is_array($tags) ? $tags : null;
    }

    $meta['catalog_link'] = $biblio['catalog_link'] ?? null;
    $meta['image_url'] = $biblio['image_cached_url'] ?? $biblio['image_url'] ?? null;

    return $meta;
}

// ─── CLI-parsning ────────────────────────────────────────────────────

function parseArgs($argv)
{
    $args = [
        'limit' => 0,
        'biblio_id' => null,
        'output' => 'json',
        'file' => null,
        'verbose' => false,
    ];

    foreach ($argv as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $args['limit'] = (int)$m[1];
        } elseif (preg_match('/^--biblio-id=(\d+)$/', $arg, $m)) {
            $args['biblio_id'] = (int)$m[1];
        } elseif (preg_match('/^--output=(json|jsonl|csv)$/', $arg, $m)) {
            $args['output'] = $m[1];
        } elseif (preg_match('/^--file=(.+)$/', $arg, $m)) {
            $args['file'] = $m[1];
        } elseif ($arg === '-v' || $arg === '--verbose') {
            $args['verbose'] = true;
        }
    }

    return $args;
}

// ─── Huvudprogram ────────────────────────────────────────────────────

function main()
{
    global $argv, $ITEM_TYPE_LABELS;
    $args = parseArgs($argv);
    $verbose = $args['verbose'];

    echo "\n";
    echo "========================================================\n";
    echo "  Förbered embedding-text för biblioteksböcker\n";
    echo "========================================================\n\n";

    $startTime = microtime(true);

    // Ladda konfiguration
    loadEnv(__DIR__ . '/../.env');

    $directusUrl = getenv('DIRECTUS_API_URL');
    $directusToken = getenv('DIRECTUS_API_TOKEN');

    if (empty($directusUrl) || empty($directusToken)) {
        throw new Exception("Saknar DIRECTUS_API_URL eller DIRECTUS_API_TOKEN i .env");
    }

    $client = new DirectusClient($directusUrl, $directusToken, $verbose);

    // ── Steg 1: Hämta biblios ──
    echo "Steg 1/4: Hämtar biblios...\n";

    $biblioFilter = ['status' => ['_eq' => 'active']];
    if ($args['biblio_id']) {
        $biblioFilter['biblio_id'] = ['_eq' => $args['biblio_id']];
    }

    $biblioFields = 'id,biblio_id,title,subtitle,author,abstract,isbn,isbn_clean,'
        . 'publisher,publication_year,publication_place,pages,material_size,'
        . 'edition_statement,series_title,age_restriction,notes,ean,issn,'
        . 'catalog_link,image_url,image_cached_url,serial,copyright_date';

    $biblios = fetchAllFromCollection($client, 'kft_koha_biblios', $biblioFields, $biblioFilter, $verbose);
    echo "  Hämtade " . count($biblios) . " biblios\n\n";

    if ($args['limit'] > 0 && count($biblios) > $args['limit']) {
        $biblios = array_slice($biblios, 0, $args['limit']);
        echo "  Begränsat till {$args['limit']} biblios\n\n";
    }

    // ── Steg 2: Hämta enriched-data ──
    echo "Steg 2/4: Hämtar enriched-data...\n";
    $enrichedFields = 'biblio_id,abstract_enriched,subjects,tags,target_audience';
    $enrichedRaw = fetchAllFromCollection($client, 'kft_koha_enriched', $enrichedFields, [], $verbose);

    $enrichedMap = [];
    foreach ($enrichedRaw as $e) {
        $enrichedMap[$e['biblio_id']] = $e;
    }
    echo "  Hämtade " . count($enrichedRaw) . " enriched-poster\n\n";

    // ── Steg 3: Hämta items ──
    echo "Steg 3/4: Hämtar items och aggregerar per biblio...\n";
    $itemFields = 'biblio_id,item_type_id,effective_item_type_id,collection_code,'
        . 'callnumber,home_library_id,location,permanent_location,'
        . 'checked_out_date,not_for_loan_status,damaged_status,lost_status,withdrawn,status';

    $items = fetchAllFromCollection($client, 'kft_koha_items', $itemFields, ['status' => ['_eq' => 'active']], $verbose);
    $itemAggMap = aggregateItemsByBiblio($items);
    echo "  Hämtade " . count($items) . " items, aggregerade till " . count($itemAggMap) . " biblios\n\n";

    // ── Steg 4: Hämta hold counts ──
    echo "Steg 4/4: Hämtar reservationsdata...\n";
    $holdFields = 'biblio_id,total_holds,holds_waiting,holds_ready,holds_in_transit,item_count';
    $holdRaw = fetchAllFromCollection($client, 'kft_koha_hold_counts', $holdFields, ['status' => ['_eq' => 'active']], $verbose);

    $holdMap = [];
    foreach ($holdRaw as $h) {
        $holdMap[$h['biblio_id']] = $h;
    }
    echo "  Hämtade " . count($holdRaw) . " hold-poster\n\n";

    // ── Bygg embedding-output ──
    echo "Bygger embedding-texter...\n";

    $results = [];
    $withEnriched = 0;
    $withItems = 0;
    $withHolds = 0;

    foreach ($biblios as $biblio) {
        $biblioId = $biblio['biblio_id'];

        $enriched = $enrichedMap[$biblioId] ?? null;
        $itemAgg = $itemAggMap[$biblioId] ?? null;
        $holdData = $holdMap[$biblioId] ?? null;

        if ($enriched) $withEnriched++;
        if ($itemAgg) $withItems++;
        if ($holdData) $withHolds++;

        $embeddingText = buildEmbeddingText($biblio, $enriched, $itemAgg, $holdData, $ITEM_TYPE_LABELS);
        $metadata = buildMetadata($biblio, $enriched, $itemAgg, $holdData, $ITEM_TYPE_LABELS);

        $results[] = [
            'biblio_id' => $biblioId,
            'embedding_text' => $embeddingText,
            'metadata' => $metadata,
        ];
    }

    // ── Skriv ut resultat ──
    $outputContent = '';

    switch ($args['output']) {
        case 'jsonl':
            foreach ($results as $r) {
                $outputContent .= json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
            }
            break;

        case 'csv':
            $outputContent = "biblio_id\ttitle\tembedding_text\n";
            foreach ($results as $r) {
                $title = str_replace("\t", ' ', $r['metadata']['title'] ?? '');
                $text = str_replace(["\t", "\n", "\r"], ' ', $r['embedding_text']);
                $outputContent .= "{$r['biblio_id']}\t{$title}\t{$text}\n";
            }
            break;

        case 'json':
        default:
            $outputContent = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
            break;
    }

    if ($args['file']) {
        file_put_contents($args['file'], $outputContent);
        echo "Skrev " . count($results) . " poster till {$args['file']}\n\n";
    } else {
        echo "\n--- OUTPUT ---\n";
        echo $outputContent;
    }

    // ── Statistik ──
    $duration = microtime(true) - $startTime;

    echo "\n========================================================\n";
    echo "  STATISTIK\n";
    echo "========================================================\n";
    echo "Biblios totalt:            " . count($results) . "\n";
    echo "Med enriched abstract:     {$withEnriched}\n";
    echo "Med item-data:             {$withItems}\n";
    echo "Med reservationer:         {$withHolds}\n";
    echo "Genomsnittlig textlängd:   ";

    if (count($results) > 0) {
        $avgLen = array_sum(array_map(function ($r) { return mb_strlen($r['embedding_text']); }, $results)) / count($results);
        echo round($avgLen) . " tecken\n";
    } else {
        echo "N/A\n";
    }

    echo "Tid:                       " . number_format($duration, 2) . "s\n";
    echo "========================================================\n\n";
}

try {
    main();
} catch (Exception $e) {
    echo "\nFel: " . $e->getMessage() . "\n";
    exit(1);
}
