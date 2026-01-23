#!/usr/bin/env php
<?php
/**
 * Sync Koha Biblios to Directus
 *
 * This script fetches books from Koha API and synchronizes them
 * to Directus using soft delete strategy (inactive instead of delete).
 *
 * Usage: php sync_koha_to_directus.php [-v|--verbose]
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Biblios Sync
 */

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

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

    // Generate image URLs
    $imageUrl = getImageUrl($firstIsbn);
    $imageCached = null;
    $imageCachedUrl = null;

    // Build catalog link
    $catalogLink = 'https://bibliotekskatalog.falkenberg.se/cgi-bin/koha/opac-detail.pl?biblionumber=' . $kohaBook['biblio_id'];

    return [
        'biblio_id' => $kohaBook['biblio_id'],

        // ISBN fields
        'isbn' => $kohaBook['isbn'] ?? null,
        'isbn_clean' => $firstIsbn,

        // Core bibliographic fields
        'title' => cleanTitle($kohaBook['title'] ?? null),
        'author' => $kohaBook['author'] ?? null,
        'abstract' => $kohaBook['abstract'] ?? null,
        'subtitle' => $kohaBook['subtitle'] ?? null,

        // Publication fields
        'publisher' => $kohaBook['publisher'] ?? null,
        'publication_year' => $kohaBook['publication_year'] ?? null,
        'publication_place' => $kohaBook['publication_place'] ?? null,

        // Physical description
        'pages' => $kohaBook['pages'] ?? null,
        'material_size' => $kohaBook['material_size'] ?? null,

        // Edition and series
        'edition_statement' => $kohaBook['edition_statement'] ?? null,
        'series_title' => $kohaBook['series_title'] ?? null,

        // Additional metadata
        'age_restriction' => $kohaBook['age_restriction'] ?? null,
        'ean' => $kohaBook['ean'] ?? null,
        'notes' => $kohaBook['notes'] ?? null,

        // URLs
        'url' => $kohaBook['url'] ?? null,
        'catalog_link' => $catalogLink,

        // Image fields (URLs only, no file upload)
        'image_url' => $imageUrl,
        'image_cached' => $imageCached,
        'image_cached_url' => $imageCachedUrl,

        // Raw data for future use
        'raw_data' => $kohaBook,

        // Status and sync timestamp
        'status' => 'active',
        'last_synced' => date('Y-m-d H:i:s')
    ];
}

/**
 * Fetch biblios from Koha API
 *
 * @param string $apiBaseUrl Base URL for Koha API
 * @param string $token OAuth access token
 * @param int $limit Number of biblios to fetch
 * @param bool $verbose Verbose output
 * @return array Array of biblios
 */
function fetchKohaBiblios($apiBaseUrl, $token, $limit = 100, $verbose = false)
{
    // Build URL with query parameters
    $url = rtrim($apiBaseUrl, '/') . '?_order_by=-biblio_id&_per_page=' . $limit;

    if ($verbose) {
        echo "   API URL: {$url}\n";
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        throw new Exception("Failed to fetch biblios from Koha API (HTTP {$httpCode}): {$error}");
    }

    $biblios = json_decode($response, true);

    if (!is_array($biblios)) {
        throw new Exception("Invalid API response: expected array of biblios");
    }

    return $biblios;
}

/**
 * Main synchronization logic
 */
function main()
{
    // Check for verbose flag
    global $argv;
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);

    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Koha → Directus Sync - Biblios                           ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    if ($verbose) {
        echo "ℹ️  Verbose mode enabled\n\n";
    }

    $startTime = microtime(true);
    $stats = [
        'koha_total' => 0,
        'directus_before' => 0,
        'created' => 0,
        'updated' => 0,
        'marked_inactive' => 0,
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

        // Step 2: Fetch biblios from Koha API
        echo "🔄 Step 2/5: Fetching 100 biblios from Koha API...\n";
        $kohaBiblios = fetchKohaBiblios(
            $config['API_BASE_URL'],
            $kohaToken,
            100,
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

        $existingData = $directusClient->getItems($collectionName, [], 10000);
        $existingBiblios = $existingData['data'] ?? [];
        $stats['directus_before'] = count($existingBiblios);

        echo "✅ Found {$stats['directus_before']} existing biblios in Directus\n\n";

        // Build lookup maps
        $existingById = [];
        foreach ($existingBiblios as $biblio) {
            $existingById[$biblio['biblio_id']] = $biblio;
        }

        // Collect all Koha IDs
        $kohaIds = [];
        foreach ($kohaBiblios as $kohaBook) {
            $kohaIds[] = $kohaBook['biblio_id'];
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
                // Transform data
                $directusData = transformKohaToDirectus($kohaBook);

                // Check if already exists
                $existing = $existingById[$biblioId] ?? null;

                if ($existing) {
                    // Update existing
                    $directusClient->updateItem($collectionName, $biblioId, $directusData);
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

        // Step 5: Mark inactive (soft delete) for biblios no longer in Koha
        echo "🔄 Step 5/5: Marking inactive biblios...\n";

        foreach ($existingBiblios as $existing) {
            $biblioId = $existing['biblio_id'];

            // If biblio no longer exists in Koha and is currently active
            if (!in_array($biblioId, $kohaIds) && $existing['status'] === 'active') {
                try {
                    $directusClient->updateItem($collectionName, $biblioId, [
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
    echo "✅ Created:                   {$stats['created']}\n";
    echo "🔄 Updated:                   {$stats['updated']}\n";
    echo "⏸️  Marked inactive:           {$stats['marked_inactive']}\n";
    echo "❌ Errors:                    " . count($stats['errors']) . "\n";
    echo "───────────────────────────────────────────────────────────\n";

    $totalActive = $stats['directus_before'] + $stats['created'] - $stats['marked_inactive'];
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
