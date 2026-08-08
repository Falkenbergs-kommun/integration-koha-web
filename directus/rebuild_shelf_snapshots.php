<?php
// Bygg last-known-good-snapshots för shelf.php från gamla cachefiler + Directus.
//
// Används när Koha är onåbar (t.ex. ImCode fail2ban-bann) för att återskapa
// fungerande boklistor: hyllmedlemskap tas från senast kända friska cachefil,
// bokmetadata hämtas färsk från Directus (kft_koha_biblios) och omslag löses
// från lokala public/images/ (+ Syndetics för saknade ISBN-omslag).
//
// Användning:
//   php rebuild_shelf_snapshots.php [--dry-run] [--shelf=N] [-v]

if (php_sapi_name() !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../common.php';
loadEnv(__DIR__ . '/../.env');

$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('-v', $argv) || in_array('--verbose', $argv);
$onlyShelf = null;
foreach ($argv as $arg) {
    if (preg_match('/^--shelf=(\d+)$/', $arg, $m)) {
        $onlyShelf = (int)$m[1];
    }
}

$cacheDir = __DIR__ . '/../cache';
$baseUrl = getenv('BASE_URL') ?: 'https://bibliotek.falkenberg.se/fbg_apps/services/koha/';

// ---------------------------------------------------------------
// 1. Hitta källfiler: cache_shelf{N}_json.cache, cache_list_{N}.json
//    samt cache.json (index.php, hårdkodad till hylla 247).
//    Förgiftade/tomma filer (items: []) skippas automatiskt.
// ---------------------------------------------------------------
$sources = []; // shelfNumber => ['file' => ..., 'data' => ..., 'items' => int]

function considerSource(&$sources, $shelfNumber, $file, $verbose) {
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data) || empty($data['items'])) {
        if ($verbose) {
            echo "  Skippar " . basename($file) . " (tom eller korrupt)\n";
        }
        return;
    }
    $count = count($data['items']);
    $existing = $sources[$shelfNumber] ?? null;
    // Flest items vinner, tie-break på nyaste filemtime
    if ($existing === null
        || $count > $existing['items']
        || ($count === $existing['items'] && filemtime($file) > filemtime($existing['file']))) {
        $sources[$shelfNumber] = ['file' => $file, 'data' => $data, 'items' => $count];
    }
}

echo "Söker källfiler i cache/...\n";

foreach (glob($cacheDir . '/cache_shelf*_json.cache') as $file) {
    if (preg_match('/cache_shelf(\d+)_json\.cache$/', $file, $m)) {
        considerSource($sources, (int)$m[1], $file, $verbose);
    }
}
foreach (glob($cacheDir . '/cache_list_*.json') as $file) {
    if (preg_match('/cache_list_(\d+)\.json$/', $file, $m)) {
        considerSource($sources, (int)$m[1], $file, $verbose);
    }
}
if (file_exists($cacheDir . '/cache.json')) {
    considerSource($sources, 247, $cacheDir . '/cache.json', $verbose);
}

if ($onlyShelf !== null) {
    $sources = array_intersect_key($sources, [$onlyShelf => true]);
}

if (empty($sources)) {
    echo "Inga användbara källfiler hittades.\n";
    exit(1);
}

ksort($sources);

// ---------------------------------------------------------------
// 2. Bygg snapshot per hylla
// ---------------------------------------------------------------
$written = 0;

foreach ($sources as $shelfNumber => $source) {
    $data = $source['data'];
    echo "\nHylla {$shelfNumber}: källa " . basename($source['file'])
       . " ({$source['items']} items, cached_at " . ($data['cached_at'] ?? '?') . ")\n";

    $biblioIds = array_filter(array_column($data['items'], 'biblio_id'));
    $directus = getBiblioMetadataFromDirectus($biblioIds);
    if (!$directus['success']) {
        echo "  VARNING: Directus-fel ({$directus['error']}) — använder enbart cachedata\n";
    }

    $hits = 0;
    $misses = 0;
    $images = 0;
    $items = [];

    foreach ($data['items'] as $item) {
        $biblioId = $item['biblio_id'] ?? null;
        $fresh = ($biblioId !== null && isset($directus['books'][(string)$biblioId]))
            ? $directus['books'][(string)$biblioId]
            : null;

        if ($fresh !== null) {
            $hits++;
        } else {
            $misses++;
        }

        // Metadata: färskt från Directus, annars gamla cachens egna värden
        $get = function($key) use ($fresh, $item) {
            if ($fresh !== null) {
                return $fresh[$key];
            }
            return $item[$key === 'title' ? 'api_title' : ($key === 'author' ? 'api_author' : $key)] ?? null;
        };

        $isbn = $fresh !== null ? $fresh['isbn'] : ($item['isbn'] ?? null);
        $firstIsbn = getFirstIsbn($isbn);
        $image = resolveBookImageLocal($firstIsbn, $biblioId, $baseUrl);
        if ($image['image_cached']) {
            $images++;
        }

        $items[] = [
            'title' => $item['title'] ?? null,
            'link' => $item['link'] ?? null,
            'description' => $item['description'] ?? null,
            'pubDate' => $item['pubDate'] ?? null,
            'guid' => $item['guid'] ?? null,
            'biblio_id' => $biblioId,
            'isbn' => $isbn,
            'isbn_clean' => $firstIsbn,
            'api_title' => $fresh !== null ? cleanTitle($fresh['title']) : ($item['api_title'] ?? null),
            'api_author' => $get('author'),
            'abstract' => $get('abstract'),
            'subtitle' => $get('subtitle'),
            'publisher' => $get('publisher'),
            'publication_year' => $get('publication_year'),
            'publication_place' => $get('publication_place'),
            'pages' => $get('pages'),
            'material_size' => $get('material_size'),
            'edition_statement' => $get('edition_statement'),
            'series_title' => $get('series_title'),
            'age_restriction' => $get('age_restriction'),
            'url' => $get('url'),
            'ean' => $get('ean'),
            'notes' => $get('notes'),
            'image_url' => $image['image_url'],
            'image_cached' => $image['image_cached'],
            'image_cached_url' => $image['image_cached_url']
        ];
    }

    $result = [
        'status' => 'ok',
        // Behåll källans cached_at — ärlig dataålder (serveringslagret sätter stale: true)
        'cached_at' => $data['cached_at'] ?? date('Y-m-d H:i:s'),
        'channel' => $data['channel'] ?? [
            'title' => '', 'link' => '', 'description' => '', 'language' => '', 'lastBuildDate' => ''
        ],
        'items' => $items
    ];

    echo "  Directus: {$hits} träffar, {$misses} från cache; {$images} omslag lokalt\n";

    $jsonFile = $cacheDir . "/snapshot_shelf{$shelfNumber}_json.cache";
    $xmlFile = $cacheDir . "/snapshot_shelf{$shelfNumber}_xml.cache";

    if ($dryRun) {
        echo "  [dry-run] Skulle skriva " . basename($jsonFile) . " + " . basename($xmlFile) . "\n";
        continue;
    }

    file_put_contents($jsonFile, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    file_put_contents($xmlFile, generateXmlOutput($result));
    echo "  Skrev " . basename($jsonFile) . " + " . basename($xmlFile) . "\n";
    $written++;
}

if ($dryRun) {
    echo "\n[dry-run] Inga filer skrivna.\n";
    exit(0);
}

echo "\nKlart: {$written} hyllor fick snapshots.\n";
exit($written > 0 ? 0 : 1);
