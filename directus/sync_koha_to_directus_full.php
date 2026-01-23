#!/usr/bin/env php
<?php
/**
 * Full Catalog Sync: Koha Biblios to Directus
 *
 * This script fetches ALL books from Koha API and synchronizes them
 * to Directus using soft delete strategy. Implements paging, delays,
 * and progress tracking for safe full catalog synchronization.
 *
 * Usage:
 *   php sync_koha_to_directus_full.php [options]
 *
 * Options:
 *   -v, --verbose         Verbose output
 *   --batch-size=N        Number of biblios per batch (default: 100)
 *   --delay=N             Delay between batches in milliseconds (default: 500)
 *   --limit=N             Limit total biblios to sync (for testing, default: all)
 *   --start-from=ID       Start from specific biblio_id (for resuming)
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
 * Safely truncate string to max length
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

/**
 * Transform Koha API data to Directus format
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
        'isbn' => safeTruncate($kohaBook['isbn'] ?? null, 255),
        'isbn_clean' => safeTruncate($firstIsbn, 50),

        // Core bibliographic fields
        'title' => safeTruncate(cleanTitle($kohaBook['title'] ?? null), 500),
        'author' => safeTruncate($kohaBook['author'] ?? null, 255),
        'abstract' => $kohaBook['abstract'] ?? null, // text field, no limit
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
        'series_title' => safeTruncate($kohaBook['series_title'] ?? null, 255),

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

        // Image fields (URLs only, no file upload)
        'image_url' => safeTruncate($imageUrl, 1000),
        'image_cached' => safeTruncate($imageCached, 500),
        'image_cached_url' => safeTruncate($imageCachedUrl, 1000),

        // Raw data for future use
        'raw_data' => $kohaBook,

        // Status and sync timestamp
        'status' => 'active',
        'last_synced' => date('Y-m-d H:i:s')
    ];
}

/**
 * Fetch a batch of biblios from Koha API with paging support
 *
 * @param string $apiBaseUrl Base URL for Koha API
 * @param string $token OAuth access token
 * @param int $batchSize Number of biblios per batch
 * @param int $page Page number (1-indexed)
 * @param bool $verbose Verbose output
 * @param int|null $startFrom Optional biblio_id to start from
 * @return array Array of biblios
 */
function fetchKohaBibliosBatch($apiBaseUrl, $token, $batchSize, $page, $verbose = false, $startFrom = null)
{
    // Build URL with pagination
    // Always sort descending (newest first)
    $url = rtrim($apiBaseUrl, '/') .
           '?_order_by=-biblio_id&_per_page=' . $batchSize .
           '&_page=' . $page;

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
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        throw new Exception("Failed to fetch biblios from Koha API (HTTP {$httpCode}): {$error}");
    }

    // Extract headers and body
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // Get total count from X-Total-Count header
    $totalCount = null;
    if (preg_match('/X-Total-Count:\s*(\d+)/i', $headers, $matches)) {
        $totalCount = intval($matches[1]);
    } elseif ($verbose) {
        // Debug: Show why header wasn't found
        $headerLines = explode("\n", $headers);
        $relevantHeaders = array_filter($headerLines, function($line) {
            return stripos($line, 'total') !== false || stripos($line, 'count') !== false;
        });
        if (!empty($relevantHeaders)) {
            echo "   Debug: Found headers with 'total' or 'count':\n";
            foreach ($relevantHeaders as $line) {
                echo "     " . trim($line) . "\n";
            }
        }
    }

    $biblios = json_decode($body, true);

    if (!is_array($biblios)) {
        throw new Exception("Invalid API response: expected array of biblios");
    }

    return [
        'data' => $biblios,
        'total_count' => $totalCount,
        'page' => $page,
        'per_page' => $batchSize
    ];
}

/**
 * Parse CLI arguments
 */
function parseArgs($argv)
{
    $options = [
        'verbose' => false,
        'batch_size' => 100,
        'delay' => 500,
        'limit' => null,
        'start_from' => null
    ];

    foreach ($argv as $arg) {
        if ($arg === '-v' || $arg === '--verbose') {
            $options['verbose'] = true;
        } elseif (preg_match('/--batch-size=(\d+)/', $arg, $matches)) {
            $options['batch_size'] = intval($matches[1]);
        } elseif (preg_match('/--delay=(\d+)/', $arg, $matches)) {
            $options['delay'] = intval($matches[1]);
        } elseif (preg_match('/--limit=(\d+)/', $arg, $matches)) {
            $options['limit'] = intval($matches[1]);
        } elseif (preg_match('/--start-from=(\d+)/', $arg, $matches)) {
            $options['start_from'] = intval($matches[1]);
        }
    }

    return $options;
}

/**
 * Format duration for display
 */
function formatDuration($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;

    if ($hours > 0) {
        return sprintf("%dh %dm %ds", $hours, $minutes, $secs);
    } elseif ($minutes > 0) {
        return sprintf("%dm %ds", $minutes, $secs);
    } else {
        return sprintf("%ds", $secs);
    }
}

/**
 * Main synchronization logic
 */
function main()
{
    global $argv;
    $options = parseArgs($argv);

    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Koha → Directus FULL CATALOG Sync                        ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    if ($options['verbose']) {
        echo "ℹ️  Verbose mode enabled\n";
    }
    echo "ℹ️  Batch size: {$options['batch_size']}\n";
    echo "ℹ️  Delay between batches: {$options['delay']}ms\n";
    if ($options['limit']) {
        echo "ℹ️  Limit: {$options['limit']} biblios\n";
    }
    if ($options['start_from']) {
        echo "ℹ️  Starting from biblio_id: {$options['start_from']}\n";
    }
    echo "\n";

    $startTime = microtime(true);
    $stats = [
        'koha_total' => 0,
        'batches_processed' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
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
        echo "🔄 Step 1/4: Getting OAuth token from Koha...\n";
        $kohaToken = getOAuthToken(
            $config['OAUTH_URL'],
            $config['CLIENT_ID'],
            $config['CLIENT_SECRET']
        );

        if (!$kohaToken) {
            throw new Exception("Failed to obtain OAuth token from Koha");
        }

        echo "✅ OAuth token obtained\n\n";

        // Step 2: Initialize Directus client
        echo "🔄 Step 2/4: Connecting to Directus...\n";
        $directusClient = new DirectusClient(
            $config['DIRECTUS_API_URL'],
            $config['DIRECTUS_API_TOKEN'],
            $options['verbose']
        );

        $collectionName = 'kft_koha_biblios';

        // Check if collection exists
        if (!$directusClient->collectionExists($collectionName)) {
            throw new Exception(
                "Collection '{$collectionName}' does not exist in Directus.\n" .
                "Please run 'php create_koha_biblios_collection.php' first."
            );
        }

        echo "✅ Connected to Directus\n\n";

        // Step 3: Fetch first batch to get total count
        echo "🔄 Step 3/4: Determining catalog size...\n";
        $firstBatch = fetchKohaBibliosBatch(
            $config['API_BASE_URL'],
            $kohaToken,
            $options['batch_size'],
            1,
            $options['verbose'],
            $options['start_from']
        );

        // Get total from header, or use a large default if not available
        $catalogTotal = $firstBatch['total_count'];
        if ($catalogTotal === null) {
            // If X-Total-Count not available, assume large catalog
            $catalogTotal = 100000; // Safe upper bound
            if ($options['verbose']) {
                echo "   Warning: X-Total-Count header not found, assuming large catalog\n";
            }
        }

        // Determine how many biblios to process
        if ($options['limit']) {
            $totalBiblios = min($options['limit'], $catalogTotal);
        } else {
            $totalBiblios = $catalogTotal;
        }

        if ($options['verbose']) {
            echo "   Catalog total from API: " . ($firstBatch['total_count'] ?? 'N/A') . "\n";
            echo "   Limit specified: " . ($options['limit'] ?? 'none') . "\n";
            if ($options['start_from']) {
                echo "   Start from biblio_id: {$options['start_from']}\n";
                echo "   Mode: Dynamic fetching (will fetch batches until limit reached)\n";
            } else {
                echo "   Biblios to sync: {$totalBiblios}\n";
            }
        }

        // Calculate starting page when start_from is specified
        $startPage = 1;
        if ($options['start_from'] && !empty($firstBatch['data'])) {
            // Get the highest biblio_id from first batch
            $maxBiblioId = $firstBatch['data'][0]['biblio_id'];

            // Estimate how many biblios to skip
            $bibliosToSkip = $maxBiblioId - $options['start_from'];

            if ($bibliosToSkip > 0) {
                // Calculate which page to start from
                $startPage = max(1, floor($bibliosToSkip / $options['batch_size']));

                if ($options['verbose']) {
                    echo "   Max biblio_id in catalog: {$maxBiblioId}\n";
                    echo "   Estimated biblios to skip: {$bibliosToSkip}\n";
                    echo "   Jumping to page: {$startPage}\n";
                }
            }

            echo "✅ Ready to sync (starting from page {$startPage}, biblio_id {$options['start_from']})\n\n";
        } else {
            $totalPages = ceil($totalBiblios / $options['batch_size']);
            echo "✅ Found {$totalBiblios} biblios to sync\n";
            echo "   Total batches: {$totalPages}\n\n";
        }

        // Step 4: Fetch existing biblios from Directus
        echo "🔄 Step 4/6: Fetching existing biblios from Directus...\n";
        $existingData = $directusClient->getItems($collectionName, [], 100000);
        $existingBiblios = $existingData['data'] ?? [];

        // Build lookup map: biblio_id => Directus id for fast existence checks
        $existingBiblioIds = [];
        foreach ($existingBiblios as $biblio) {
            $existingBiblioIds[$biblio['biblio_id']] = $biblio['id'];
        }

        echo "✅ Found " . count($existingBiblioIds) . " existing biblios in Directus\n\n";

        // Step 5 & 6: Fetch and synchronize biblios (combined for dynamic fetching)
        echo "🔄 Step 5/6: Fetching and synchronizing biblios...\n";
        echo "───────────────────────────────────────────────────────────\n\n";

        $processedCount = 0;
        $currentPage = $startPage;

        // If we're starting from a later page, fetch that batch instead of using firstBatch
        if ($startPage > 1) {
            $currentBatch = fetchKohaBibliosBatch(
                $config['API_BASE_URL'],
                $kohaToken,
                $options['batch_size'],
                $startPage,
                $options['verbose'],
                $options['start_from']
            );
        } else {
            $currentBatch = $firstBatch;
        }

        $limitReached = false;

        // Process batches dynamically
        while (!$limitReached && !empty($currentBatch['data'])) {
            $kohaBiblios = $currentBatch['data'];
            $batchStartTime = microtime(true);
            $batchItemCount = 0;

            echo "📦 Batch {$currentPage} ({" . count($kohaBiblios) . "} biblios)\n";

            foreach ($kohaBiblios as $kohaBook) {
                $biblioId = $kohaBook['biblio_id'];

                // Check if we should skip (start_from filter)
                // With descending sort (newest first), skip books above start_from
                if ($options['start_from'] && $biblioId > $options['start_from']) {
                    $stats['skipped']++;
                    continue;
                }

                // Check if we've reached the limit
                if ($options['limit'] && $processedCount >= $options['limit']) {
                    $limitReached = true;
                    break;
                }

                $processedCount++;
                $batchItemCount++;

                try {
                    // Transform data
                    $directusData = transformKohaToDirectus($kohaBook);

                    // Check if item exists using our pre-fetched lookup
                    if (isset($existingBiblioIds[$biblioId])) {
                        // Update existing - use Directus id, not biblio_id!
                        $directusId = $existingBiblioIds[$biblioId];
                        $directusClient->updateItem($collectionName, $directusId, $directusData);
                        $stats['updated']++;
                    } else {
                        // Create new
                        $directusClient->createItem($collectionName, $directusData);
                        $stats['created']++;
                    }

                    // Progress indicator
                    if ($options['verbose'] || $processedCount % 10 === 0) {
                        $elapsed = microtime(true) - $startTime;
                        $rate = $processedCount / $elapsed;
                        $remaining = ($totalBiblios - $processedCount) / $rate;

                        echo sprintf(
                            "  [%d/%d] biblio_id=%d | Rate: %.1f/s | ETA: %s\n",
                            $processedCount,
                            $totalBiblios,
                            $biblioId,
                            $rate,
                            formatDuration(intval($remaining))
                        );
                    }

                } catch (Exception $e) {
                    $error = "Failed to sync {$biblioId}: " . $e->getMessage();
                    $stats['errors'][] = $error;

                    if ($options['verbose']) {
                        echo "  ❌ {$error}\n";
                    }
                }
            }

            $batchDuration = microtime(true) - $batchStartTime;

            if ($batchItemCount > 0) {
                $stats['batches_processed']++;
                echo sprintf("  ✅ Batch completed: %d items in %.2fs\n", $batchItemCount, $batchDuration);
            }

            // Fetch next batch if not reached limit yet
            if (!$limitReached) {
                $currentPage++;

                // Delay between batches
                if ($options['delay'] > 0) {
                    echo "  ⏱️  Waiting {$options['delay']}ms...\n";
                    usleep($options['delay'] * 1000);
                }

                // Fetch next batch
                try {
                    $currentBatch = fetchKohaBibliosBatch(
                        $config['API_BASE_URL'],
                        $kohaToken,
                        $options['batch_size'],
                        $currentPage,
                        $options['verbose'],
                        $options['start_from']
                    );
                } catch (Exception $e) {
                    if ($options['verbose']) {
                        echo "  ⚠️  No more batches available\n";
                    }
                    break;
                }
            }

            echo "\n";
        }

        $stats['koha_total'] = $processedCount;

        echo "✅ Synchronization complete\n\n";

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
    echo "📊 Biblios processed:         {$stats['koha_total']}\n";
    echo "📊 Batches processed:         {$stats['batches_processed']}\n";
    echo "───────────────────────────────────────────────────────────\n";
    echo "✅ Created:                   {$stats['created']}\n";
    echo "🔄 Updated:                   {$stats['updated']}\n";
    echo "⏭️  Skipped:                   {$stats['skipped']}\n";
    echo "❌ Errors:                    " . count($stats['errors']) . "\n";
    echo "───────────────────────────────────────────────────────────\n";

    $rate = $stats['koha_total'] / $duration;
    echo "⏱️  Duration:                  " . formatDuration(intval($duration)) . "\n";
    echo "⚡ Rate:                      " . number_format($rate, 2) . " biblios/s\n";
    echo "\n";

    // Print errors if any
    if (count($stats['errors']) > 0) {
        echo "Errors:\n";
        $errorCount = min(10, count($stats['errors'])); // Show max 10 errors
        for ($i = 0; $i < $errorCount; $i++) {
            echo "  • {$stats['errors'][$i]}\n";
        }
        if (count($stats['errors']) > 10) {
            echo "  ... and " . (count($stats['errors']) - 10) . " more errors\n";
        }
        echo "\n";
    }

    echo "✨ Full catalog sync complete!\n";
    echo "🔗 View at: {$config['DIRECTUS_API_URL']}/admin/content/kft_koha_biblios\n\n";
}

// Run main function
main();
?>
