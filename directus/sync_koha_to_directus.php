#!/usr/bin/env php
<?php
/**
 * Sync Koha Biblios to Directus
 *
 * This script fetches books from Koha API and synchronizes them
 * to Directus using soft delete strategy (inactive instead of delete).
 *
 * Usage: php sync_koha_to_directus.php [-v|--verbose] [--force-update]
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Biblios Sync
 */

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

// safeTruncate() is now in common.php

/**
 * Transform Koha API data to Directus format
 *
 * @param array $kohaBook Koha API biblio data
 * @return array Directus-formatted data
 */
function transformKohaToDirectus($kohaBook)
{
    // Extract first ISBN
    $firstIsbn = getFirstIsbn($kohaBook['isbn'] ?? null);

    // Generate Syndetics image URL from ISBN
    $imageUrl = getImageUrl($firstIsbn);

    // Build catalog link
    $catalogLink = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=' . $kohaBook['biblio_id'];

    return [
        'biblio_id' => $kohaBook['biblio_id'],

        // ISBN fields
        'isbn' => safeTruncate($kohaBook['isbn'] ?? null, 255),
        'isbn_clean' => safeTruncate($firstIsbn, 50),

        // Core bibliographic fields
        'title' => safeTruncate(cleanTitle($kohaBook['title'] ?? null), 500),
        'author' => safeTruncate($kohaBook['author'] ?? null, 255),
        'abstract' => $kohaBook['abstract'] ?? null, // text field, no limit
        'part_number' => safeTruncate($kohaBook['part_number'] ?? null, 255),
        'part_name' => safeTruncate($kohaBook['part_name'] ?? null, 255),
        'subtitle' => safeTruncate($kohaBook['subtitle'] ?? null, 500),

        // Publication fields
        'publisher' => safeTruncate($kohaBook['publisher'] ?? null, 255),
        'publication_year' => safeTruncate($kohaBook['publication_year'] ?? null, 50),
        'publication_place' => safeTruncate($kohaBook['publication_place'] ?? null, 255),

        // Physical description
        'pages' => safeTruncate($kohaBook['pages'] ?? null, 100),
        'material_size' => safeTruncate($kohaBook['material_size'] ?? null, 100),

        // Edition and series
        'edition_statement' => safeTruncate($kohaBook['edition_statement'] ?? null, 255),
        'series_title' => !empty($kohaBook['series_title']) ? [$kohaBook['series_title']] : null,

        // Additional metadata
        'age_restriction' => safeTruncate($kohaBook['age_restriction'] ?? null, 50),
        'ean' => safeTruncate($kohaBook['ean'] ?? null, 50),
        'issn' => safeTruncate($kohaBook['issn'] ?? null, 50),
        'notes' => $kohaBook['notes'] ?? null, // text field, no limit

        // Koha timestamps and metadata
        'creation_date' => $kohaBook['creation_date'] ?? null,
        'koha_timestamp' => $kohaBook['timestamp'] ?? null,
        'copyright_date' => safeTruncate($kohaBook['copyright_date'] ?? null, 50),
        'lc_control_number' => safeTruncate($kohaBook['lc_control_number'] ?? null, 100),
        'serial' => $kohaBook['serial'] ?? false,

        // URLs
        'url' => safeTruncate($kohaBook['url'] ?? null, 1000),
        'catalog_link' => safeTruncate($catalogLink, 500),

        // Image URL from Syndetics (derived from ISBN)
        'image_url' => safeTruncate($imageUrl, 1000),
        // NOTE: image_cached and image_cached_url are intentionally excluded here.
        // They are populated by the web endpoints (common.php/latest.php) when images
        // are downloaded and cached locally. Overwriting them with null on every sync
        // would destroy cached image references.

        // Raw data for future use
        'raw_data' => $kohaBook,

        // Status and sync timestamp
        'status' => 'active',
        'last_synced' => date('Y-m-d H:i:s')
    ];
}

/**
 * Fetch ALL biblios from Koha API using pagination.
 *
 * @param string $apiBaseUrl Base URL for Koha API
 * @param string $token OAuth access token
 * @param bool $verbose Verbose output
 * @return array Array of all biblios
 */
function fetchKohaBiblios($apiBaseUrl, $token, $verbose = false)
{
    $baseUrl = rtrim($apiBaseUrl, '/');
    $perPage = 500;
    $page = 1;
    $all = [];

    while (true) {
        $url = "{$baseUrl}?_order_by=-biblio_id&_per_page={$perPage}&_page={$page}";

        if ($verbose) {
            echo "   Fetching page {$page}: {$url}\n";
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception("Failed to fetch biblios from Koha API page {$page} (HTTP {$httpCode}): {$error}");
        }

        $biblios = json_decode($response, true);

        if (!is_array($biblios)) {
            throw new Exception("Invalid API response on page {$page}: expected array of biblios");
        }

        $all = array_merge($all, $biblios);
        echo "  Page {$page}: " . count($biblios) . " biblios (total so far: " . count($all) . ")\n";

        // If fewer results than page size, we've reached the last page
        if (count($biblios) < $perPage) {
            break;
        }

        $page++;
    }

    return $all;
}

/**
 * Fetch ALL items from a Directus collection using offset pagination.
 *
 * @param string $directusUrl Base Directus URL
 * @param string $token API token
 * @param string $collection Collection name
 * @param string $fields Comma-separated fields to fetch
 * @return array All items
 */
function fetchAllDirectusItems($directusUrl, $token, $collection, $fields = '*')
{
    $baseUrl = rtrim($directusUrl, '/');
    $perPage = 500;
    $offset = 0;
    $all = [];

    while (true) {
        $url = "{$baseUrl}/items/{$collection}?limit={$perPage}&offset={$offset}&fields={$fields}&sort=id";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch Directus items at offset {$offset} (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        $items = $data['data'] ?? [];
        $all = array_merge($all, $items);

        echo "  Offset {$offset}: " . count($items) . " items (total so far: " . count($all) . ")\n";

        if (count($items) < $perPage) {
            break;
        }

        $offset += $perPage;
    }

    return $all;
}

/**
 * Main synchronization logic
 */
function main()
{
    // Check for flags
    global $argv;
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);
    $forceUpdate = in_array('--force-update', $argv);

    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Koha → Directus Sync - Biblios                           ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    if ($verbose) {
        echo "Verbose mode enabled\n";
    }
    if ($forceUpdate) {
        echo "Force update: will update ALL biblios regardless of timestamp\n";
    }
    echo "\n";

    $startTime = microtime(true);
    $stats = [
        'koha_total' => 0,
        'directus_before' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'marked_inactive' => 0,
        'duplicates_deleted' => 0,
        'errors' => []
    ];

    try {
        // Load configuration
        echo "📁 Loading configuration...\n";
        loadEnv(__DIR__ . '/../.env');

        $config = [
            'OAUTH_URL' => getenv('OAUTH_URL'),
            'CLIENT_ID' => getenv('CLIENT_ID'),
            'CLIENT_SECRET' => getenv('CLIENT_SECRET'),
            'API_BASE_URL' => getenv('API_BASE_URL'),
            'DIRECTUS_API_URL' => getenv('DIRECTUS_API_URL'),
            'DIRECTUS_API_TOKEN' => getenv('DIRECTUS_API_TOKEN')
        ];

        // Validate required config
        $required = ['OAUTH_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'API_BASE_URL',
                     'DIRECTUS_API_URL', 'DIRECTUS_API_TOKEN'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new Exception("Missing required configuration: {$key}");
            }
        }

        echo "✅ Configuration loaded\n\n";

        // Step 1: Get OAuth token from Koha
        echo "🔄 Step 1/5: Getting OAuth token from Koha...\n";
        $kohaToken = getOAuthToken(
            $config['OAUTH_URL'],
            $config['CLIENT_ID'],
            $config['CLIENT_SECRET']
        );

        if (!$kohaToken) {
            throw new Exception("Failed to obtain OAuth token from Koha");
        }

        echo "✅ OAuth token obtained\n\n";

        // Step 2: Fetch ALL biblios from Koha API (paginated)
        echo "🔄 Step 2/5: Fetching all biblios from Koha API (paginated)...\n";
        $kohaBiblios = fetchKohaBiblios(
            $config['API_BASE_URL'],
            $kohaToken,
            $verbose
        );

        $stats['koha_total'] = count($kohaBiblios);
        echo "✅ Fetched {$stats['koha_total']} biblios from Koha\n\n";

        // Step 3: Fetch existing biblios from Directus
        echo "🔄 Step 3/5: Fetching existing biblios from Directus...\n";
        $directusClient = new DirectusClient(
            $config['DIRECTUS_API_URL'],
            $config['DIRECTUS_API_TOKEN'],
            $verbose
        );

        $collectionName = 'kft_koha_biblios';

        // Check if collection exists
        if (!$directusClient->collectionExists($collectionName)) {
            throw new Exception(
                "Collection '{$collectionName}' does not exist in Directus.\n" .
                "Please run 'php create_koha_biblios_collection.php' first."
            );
        }

        $existingBiblios = fetchAllDirectusItems(
            $config['DIRECTUS_API_URL'],
            $config['DIRECTUS_API_TOKEN'],
            $collectionName,
            'id,biblio_id,status,koha_timestamp'
        );
        $stats['directus_before'] = count($existingBiblios);

        echo "✅ Found {$stats['directus_before']} existing biblios in Directus\n\n";

        // Build lookup maps (use hash maps for O(1) lookup instead of O(n) in_array)
        // Results arrive sorted by id ASC, so when a biblio_id appears twice the second
        // entry has a higher id (= most recently synced). Collect the lower-id duplicates
        // for deletion so they don't silently accumulate across syncs.
        $existingById = [];
        $duplicateIdsToDelete = [];

        foreach ($existingBiblios as $biblio) {
            $biblioId = $biblio['biblio_id'];
            if (isset($existingById[$biblioId])) {
                // Already seen this biblio_id – the stored entry has a lower id.
                // Keep the higher-id record (this one) and schedule the lower-id for deletion.
                $duplicateIdsToDelete[] = $existingById[$biblioId]['id'];
            }
            $existingById[$biblioId] = $biblio;
        }

        if (count($duplicateIdsToDelete) > 0) {
            echo "  ⚠️  Found " . count($duplicateIdsToDelete) . " duplicate records – will clean up after sync\n";
        }

        // Collect all Koha IDs as hash map for fast soft-delete check
        $kohaIds = [];
        foreach ($kohaBiblios as $kohaBook) {
            $kohaIds[$kohaBook['biblio_id']] = true;
        }

        // Step 4: Sync biblios (create/update)
        echo "🔄 Step 4/5: Synchronizing biblios...\n";

        foreach ($kohaBiblios as $index => $kohaBook) {
            $biblioId = $kohaBook['biblio_id'];

            $progressNum = $index + 1;
            if ($verbose || $progressNum % 10 === 0 || $progressNum === $stats['koha_total']) {
                echo "  Processing: {$progressNum}/{$stats['koha_total']} - biblio_id={$biblioId}\n";
            }

            try {
                // Check if already exists
                $existing = $existingById[$biblioId] ?? null;

                if ($existing && !$forceUpdate) {
                    // Skip update if koha_timestamp hasn't changed
                    // Formats differ: Koha uses +01:00, Directus uses .000Z (UTC)
                    // Compare as unix timestamps to normalize
                    $kohaTs = $kohaBook['timestamp'] ?? null;
                    $directusTs = $existing['koha_timestamp'] ?? null;

                    if ($kohaTs !== null && $directusTs !== null
                        && strtotime($kohaTs) === strtotime($directusTs)) {
                        $stats['skipped']++;
                        continue;
                    }
                }

                // Transform data
                $directusData = transformKohaToDirectus($kohaBook);

                // Hämta MARC-data (publication_year, series, ämnesord, genre, m.m.)
                $marcUrl = rtrim($config['API_BASE_URL'], '/') . '/' . $biblioId;
                $marcRecord = fetchMarcRecord($marcUrl, $kohaToken);

                if ($marcRecord !== null) {
                    $marcFields = extractFieldsFromMarc($marcRecord);

                    // publication_year: MARC-värdet föredras (kan vara bandspecifikt via 245$p)
                    if (!empty($marcFields['publication_year'])) {
                        $directusData['publication_year'] = $marcFields['publication_year'];
                    }

                    // publication_period: datumintervall från 260$c/264$c (t.ex. "1967-1991")
                    if (!empty($marcFields['publication_period'])) {
                        $directusData['publication_period'] = $marcFields['publication_period'];
                    }

                    // series_title: MARC 490 som fallback
                    if (empty($directusData['series_title']) && !empty($marcFields['series_title'])) {
                        $directusData['series_title'] = $marcFields['series_title'];
                    }

                    // Fält som bara finns i MARC (ej i REST API)
                    if (!empty($marcFields['language_code']))      $directusData['language_code'] = $marcFields['language_code'];
                    if (!empty($marcFields['subjects']))            $directusData['subjects_marc'] = $marcFields['subjects'];
                    if (!empty($marcFields['genre_form']))          $directusData['genre_form'] = $marcFields['genre_form'];
                    if (!empty($marcFields['sab_classification']))  $directusData['sab_classification'] = safeTruncate($marcFields['sab_classification'], 50);
                    if (!empty($marcFields['contributors']))        $directusData['contributors'] = $marcFields['contributors'];
                }

                if ($existing) {
                    // Update existing - use Directus id, not biblio_id!
                    $directusClient->updateItem($collectionName, $existing['id'], $directusData);
                    $stats['updated']++;
                } else {
                    // Create new
                    $directusClient->createItem($collectionName, $directusData);
                    $stats['created']++;
                }

            } catch (Exception $e) {
                $error = "Failed to sync {$biblioId}: " . $e->getMessage();
                $stats['errors'][] = $error;

                if ($verbose) {
                    echo "  ❌ {$error}\n";
                }
            }
        }

        echo "✅ Synchronization complete\n\n";

        // Step 4b: Delete duplicate records collected during map-build
        if (count($duplicateIdsToDelete) > 0) {
            echo "🔄 Step 4b: Deleting " . count($duplicateIdsToDelete) . " duplicate records...\n";
            $deleteBatches = array_chunk($duplicateIdsToDelete, 200);
            $deletedCount = 0;

            foreach ($deleteBatches as $batchNum => $batch) {
                try {
                    $directusClient->deleteItems($collectionName, $batch);
                    $deletedCount += count($batch);
                    echo "  Deleted batch " . ($batchNum + 1) . "/" . count($deleteBatches)
                         . " (" . $deletedCount . "/" . count($duplicateIdsToDelete) . " total)\n";
                } catch (Exception $e) {
                    $error = "Failed to delete duplicate batch " . ($batchNum + 1) . ": " . $e->getMessage();
                    $stats['errors'][] = $error;
                    if ($verbose) {
                        echo "  ❌ {$error}\n";
                    }
                }
            }

            $stats['duplicates_deleted'] = $deletedCount;
            echo "✅ Duplicate cleanup complete\n\n";
        }

        // Step 5: Mark inactive (soft delete) for biblios no longer in Koha
        echo "🔄 Step 5/5: Marking inactive biblios...\n";

        // Skip records that were already hard-deleted in step 4b
        $deletedIdSet = array_flip($duplicateIdsToDelete);

        foreach ($existingBiblios as $existing) {
            if (isset($deletedIdSet[$existing['id']])) {
                continue;
            }

            $biblioId = $existing['biblio_id'];
            $directusId = $existing['id'];

            // If biblio no longer exists in Koha and is currently active
            if (!isset($kohaIds[$biblioId]) && $existing['status'] === 'active') {
                try {
                    // Use Directus id, not biblio_id!
                    $directusClient->updateItem($collectionName, $directusId, [
                        'status' => 'inactive',
                        'last_synced' => date('Y-m-d H:i:s')
                    ]);
                    $stats['marked_inactive']++;

                    if ($verbose) {
                        echo "  Marked inactive: {$biblioId}\n";
                    }
                } catch (Exception $e) {
                    $error = "Failed to mark inactive {$biblioId}: " . $e->getMessage();
                    $stats['errors'][] = $error;

                    if ($verbose) {
                        echo "  ❌ {$error}\n";
                    }
                }
            }
        }

        echo "✅ Soft delete complete\n\n";

    } catch (Exception $e) {
        echo "\n❌ Fatal error: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Calculate duration
    $duration = microtime(true) - $startTime;

    // Print statistics
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  SYNC STATISTICS                                           ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "📊 Koha biblios:              {$stats['koha_total']}\n";
    echo "📊 Directus before sync:      {$stats['directus_before']}\n";
    echo "───────────────────────────────────────────────────────────\n";
    echo "Created:                   {$stats['created']}\n";
    echo "Updated:                   {$stats['updated']}\n";
    echo "Skipped (unchanged):       {$stats['skipped']}\n";
    echo "Marked inactive:           {$stats['marked_inactive']}\n";
    echo "🧹 Duplicates deleted:        {$stats['duplicates_deleted']}\n";
    echo "❌ Errors:                    " . count($stats['errors']) . "\n";
    echo "───────────────────────────────────────────────────────────\n";

    $totalActive = $stats['directus_before'] + $stats['created'] - $stats['marked_inactive'] - $stats['duplicates_deleted'];
    echo "📚 Total in Directus now:     {$totalActive}\n";
    echo "⏱️  Duration:                  " . number_format($duration, 2) . "s\n";
    echo "\n";

    // Print errors if any
    if (count($stats['errors']) > 0) {
        echo "Errors:\n";
        foreach ($stats['errors'] as $error) {
            echo "  • {$error}\n";
        }
        echo "\n";
    }

    echo "✨ Sync complete!\n";
    echo "🔗 View at: {$config['DIRECTUS_API_URL']}/admin/content/kft_koha_biblios\n\n";
}

// Run main function
main();
?>
