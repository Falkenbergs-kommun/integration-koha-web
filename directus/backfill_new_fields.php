#!/usr/bin/env php
<?php
/**
 * Backfill New Metadata Fields for Existing Records
 *
 * This script updates existing records in Directus with the newly added
 * metadata fields (creation_date, koha_timestamp, etc.) by fetching fresh
 * data from Koha API.
 *
 * Usage: php backfill_new_fields.php [--limit=N] [-v|--verbose]
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Biblios Sync
 */

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

// Parse CLI arguments
$options = [
    'limit' => null,
    'verbose' => false
];

foreach ($argv as $arg) {
    if ($arg === '-v' || $arg === '--verbose') {
        $options['verbose'] = true;
    } elseif (preg_match('/--limit=(\d+)/', $arg, $matches)) {
        $options['limit'] = intval($matches[1]);
    }
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Koha → Directus Backfill - New Metadata Fields          ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

if ($options['verbose']) {
    echo "ℹ️  Verbose mode enabled\n\n";
}

$startTime = microtime(true);
$stats = [
    'total' => 0,
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

    // Step 2: Fetch existing biblios from Directus
    echo "🔄 Step 2/4: Fetching existing biblios from Directus...\n";
    $directusClient = new DirectusClient(
        $config['DIRECTUS_API_URL'],
        $config['DIRECTUS_API_TOKEN'],
        $options['verbose']
    );

    $collectionName = 'kft_koha_biblios';

    $existingData = $directusClient->getItems($collectionName, [], 100000);
    $existingBiblios = $existingData['data'] ?? [];
    $stats['total'] = count($existingBiblios);

    // Build lookup map: biblio_id => Directus id
    $biblioIdToDirectusId = [];
    foreach ($existingBiblios as $biblio) {
        $biblioIdToDirectusId[$biblio['biblio_id']] = $biblio['id'];
    }

    // Apply limit if specified
    if ($options['limit']) {
        $existingBiblios = array_slice($existingBiblios, 0, $options['limit']);
        echo "✅ Found {$stats['total']} biblios, processing first {$options['limit']}\n\n";
    } else {
        echo "✅ Found {$stats['total']} existing biblios in Directus\n\n";
    }

    // Step 3: Fetch fresh data from Koha and update
    echo "🔄 Step 3/4: Fetching fresh metadata from Koha and updating...\n";
    echo "───────────────────────────────────────────────────────────\n\n";

    $processedCount = 0;
    $limit = $options['limit'] ?: $stats['total'];

    foreach ($existingBiblios as $existing) {
        $biblioId = $existing['biblio_id'];
        $directusId = $existing['id']; // Use Directus id, not biblio_id
        $processedCount++;

        try {
            // Fetch fresh data from Koha
            $bookData = getBookDataFromApi($biblioId, $config['API_BASE_URL'], $kohaToken);

            // Check if we got valid data
            if (!$bookData['title'] && !$bookData['isbn']) {
                $stats['skipped']++;
                if ($options['verbose']) {
                    echo "  [{$processedCount}/{$limit}] Skipping biblio_id={$biblioId} - No data from Koha\n";
                }
                continue;
            }

            // Prepare update with ONLY the new fields
            $updateData = [
                'issn' => $bookData['issn'],
                'creation_date' => $bookData['creation_date'],
                'koha_timestamp' => $bookData['timestamp'],
                'copyright_date' => $bookData['copyright_date'],
                'lc_control_number' => $bookData['lc_control_number'],
                'serial' => $bookData['serial'] ?? false,
                'last_synced' => date('Y-m-d H:i:s')
            ];

            // Update in Directus using Directus id (not biblio_id!)
            $directusClient->updateItem($collectionName, $directusId, $updateData);
            $stats['updated']++;

            // Progress indicator
            if ($options['verbose'] || $processedCount % 10 === 0 || $processedCount === $limit) {
                $elapsed = microtime(true) - $startTime;
                $rate = $processedCount / $elapsed;
                $remaining = ($limit - $processedCount) / $rate;

                echo sprintf(
                    "  [%d/%d] Updated biblio_id=%d | Rate: %.1f/s | ETA: %s\n",
                    $processedCount,
                    $limit,
                    $biblioId,
                    $rate,
                    formatDuration(intval($remaining))
                );
            }

            // Small delay to avoid overwhelming the APIs
            usleep(100000); // 100ms delay

        } catch (Exception $e) {
            $error = "Failed to update {$biblioId}: " . $e->getMessage();
            $stats['errors'][] = $error;

            if ($options['verbose']) {
                echo "  ❌ {$error}\n";
            }
        }
    }

    echo "\n✅ Backfill complete\n\n";

} catch (Exception $e) {
    echo "\n❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

// Calculate duration
$duration = microtime(true) - $startTime;

// Print statistics
echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  BACKFILL STATISTICS                                       ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📊 Total biblios:             {$stats['total']}\n";
echo "📊 Processed:                 {$processedCount}\n";
echo "───────────────────────────────────────────────────────────\n";
echo "✅ Updated:                   {$stats['updated']}\n";
echo "⏭️  Skipped (no data):         {$stats['skipped']}\n";
echo "❌ Errors:                    " . count($stats['errors']) . "\n";
echo "───────────────────────────────────────────────────────────\n";
echo "⏱️  Duration:                  " . number_format($duration, 2) . "s\n";
echo "📈 Rate:                      " . number_format($processedCount / $duration, 1) . " items/s\n";
echo "\n";

// Print errors if any
if (count($stats['errors']) > 0) {
    echo "Errors:\n";
    foreach (array_slice($stats['errors'], 0, 10) as $error) {
        echo "  • {$error}\n";
    }
    if (count($stats['errors']) > 10) {
        echo "  ... and " . (count($stats['errors']) - 10) . " more errors\n";
    }
    echo "\n";
}

echo "✨ Backfill complete!\n";
echo "🔗 View at: {$config['DIRECTUS_API_URL']}/admin/content/kft_koha_biblios\n\n";

/**
 * Format duration in human-readable format
 */
function formatDuration($seconds)
{
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return $minutes . 'm ' . $secs . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}
