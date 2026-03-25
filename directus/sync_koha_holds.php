#!/usr/bin/env php
<?php
/**
 * Sync Koha Holds (Reservations) to Directus
 *
 * Fetches all active holds from Koha API, aggregates per biblio,
 * and syncs to kft_koha_hold_counts in Directus.
 * Also updates hold_count and item_count on kft_koha_biblios.
 *
 * GDPR: Only aggregated counts are stored — patron_id is never saved.
 *
 * Usage: php sync_koha_holds.php [-v|--verbose]
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Hold Counts Sync
 */

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

/**
 * Fetch all holds from Koha API with pagination
 *
 * @param string $apiBaseUrl Base URL for Koha API (points to /biblios/)
 * @param string $token OAuth access token
 * @return array Array of hold objects
 */
function fetchAllKohaHolds($apiBaseUrl, $token)
{
    $baseApiUrl = preg_replace('#/biblios/?$#', '', $apiBaseUrl);
    $allHolds = [];
    $page = 1;
    $perPage = 500;

    while (true) {
        $url = rtrim($baseApiUrl, '/') . "/holds?_per_page={$perPage}&_page={$page}&_order_by=hold_id";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Authorization: Bearer ' . $token
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception("Failed to fetch holds from Koha API page {$page} (HTTP {$httpCode}): {$error}");
        }

        $holds = json_decode($response, true);

        if (!is_array($holds)) {
            throw new Exception("Invalid API response on page {$page}: expected array");
        }

        if (empty($holds)) {
            break;
        }

        $allHolds = array_merge($allHolds, $holds);
        $page++;
    }

    return $allHolds;
}

/**
 * Aggregate holds per biblio_id
 *
 * @param array $holds Raw holds from Koha API
 * @return array Map of biblio_id => aggregated data
 */
function aggregateHoldsByBiblio($holds)
{
    $aggregated = [];

    foreach ($holds as $hold) {
        $biblioId = $hold['biblio_id'] ?? null;
        if ($biblioId === null) {
            continue;
        }

        if (!isset($aggregated[$biblioId])) {
            $aggregated[$biblioId] = [
                'biblio_id' => $biblioId,
                'total_holds' => 0,
                'holds_waiting' => 0,
                'holds_ready' => 0,
                'holds_in_transit' => 0,
                'pickup_libraries' => [],
                'oldest_hold_date' => null
            ];
        }

        $agg = &$aggregated[$biblioId];
        $agg['total_holds']++;

        // Raknare per status: null = koar, W = redo, T = transit
        $status = $hold['status'] ?? null;
        if ($status === null) {
            $agg['holds_waiting']++;
        } elseif ($status === 'W') {
            $agg['holds_ready']++;
        } elseif ($status === 'T') {
            $agg['holds_in_transit']++;
        }

        // Uphamtningsbibliotek
        $pickupLib = $hold['pickup_library_id'] ?? null;
        if ($pickupLib !== null) {
            if (!isset($agg['pickup_libraries'][$pickupLib])) {
                $agg['pickup_libraries'][$pickupLib] = 0;
            }
            $agg['pickup_libraries'][$pickupLib]++;
        }

        // Aldsta hold_date
        $holdDate = $hold['hold_date'] ?? null;
        if ($holdDate !== null) {
            if ($agg['oldest_hold_date'] === null || $holdDate < $agg['oldest_hold_date']) {
                $agg['oldest_hold_date'] = $holdDate;
            }
        }

        unset($agg);
    }

    // Konvertera pickup_libraries till sorterad array
    foreach ($aggregated as &$agg) {
        $pickupArr = [];
        foreach ($agg['pickup_libraries'] as $libId => $count) {
            $pickupArr[] = ['library_id' => $libId, 'count' => $count];
        }
        // Sortera fallande pa antal
        usort($pickupArr, function ($a, $b) {
            return $b['count'] - $a['count'];
        });
        $agg['pickup_libraries'] = $pickupArr;
    }
    unset($agg);

    return $aggregated;
}

/**
 * Fetch item counts per biblio from Directus kft_koha_items
 *
 * Paginerar genom alla items och raknar per biblio_id.
 *
 * @param DirectusClient $client Directus client
 * @param bool $verbose Enable verbose output
 * @return array Map of biblio_id => item count
 */
function fetchItemCountsPerBiblio($client, $verbose = false)
{
    $itemCounts = [];
    $offset = 0;
    $limit = 5000;

    while (true) {
        $url = "kft_koha_items?fields=biblio_id&filter[status][_eq]=active&limit={$limit}&offset={$offset}&sort=id";

        // Anvand ratt URL via getItems med extra params
        $params = [
            'fields' => 'biblio_id',
            'filter' => json_encode(['status' => ['_eq' => 'active']]),
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'id'
        ];

        $query = http_build_query($params);
        $fullUrl = rtrim($client->getBaseUrl(), '/') . "/items/kft_koha_items?{$query}";

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
            throw new Exception("Failed to fetch items for counting (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        $items = $data['data'] ?? [];

        if (empty($items)) {
            break;
        }

        foreach ($items as $item) {
            $biblioId = $item['biblio_id'] ?? null;
            if ($biblioId !== null) {
                $itemCounts[$biblioId] = ($itemCounts[$biblioId] ?? 0) + 1;
            }
        }

        if ($verbose) {
            echo "  Fetched items offset {$offset}, got " . count($items) . " items\n";
        }

        if (count($items) < $limit) {
            break;
        }

        $offset += $limit;
    }

    return $itemCounts;
}

/**
 * Fetch all existing hold counts from Directus (paginated)
 *
 * @param DirectusClient $client
 * @param bool $verbose
 * @return array All hold count records
 */
function fetchAllDirectusHoldCounts($client, $verbose = false)
{
    $all = [];
    $offset = 0;
    $limit = 1000;

    while (true) {
        $params = [
            'fields' => 'id,biblio_id,total_holds,item_count,status',
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'id'
        ];

        $query = http_build_query($params);
        $fullUrl = rtrim($client->getBaseUrl(), '/') . "/items/kft_koha_hold_counts?{$query}";

        $ch = curl_init($fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $client->getToken(),
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch hold counts from Directus (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        $items = $data['data'] ?? [];

        if (empty($items)) {
            break;
        }

        $all = array_merge($all, $items);

        if (count($items) < $limit) {
            break;
        }

        $offset += $limit;
    }

    return $all;
}

/**
 * Fetch all biblios with hold_count and item_count from Directus (paginated)
 *
 * @param DirectusClient $client
 * @param bool $verbose
 * @return array All biblio records with relevant fields
 */
function fetchAllDirectusBiblios($client, $verbose = false)
{
    $all = [];
    $offset = 0;
    $limit = 5000;

    while (true) {
        $params = [
            'fields' => 'id,biblio_id,hold_count,item_count',
            'limit' => $limit,
            'offset' => $offset,
            'sort' => 'id'
        ];

        $query = http_build_query($params);
        $fullUrl = rtrim($client->getBaseUrl(), '/') . "/items/kft_koha_biblios?{$query}";

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
            throw new Exception("Failed to fetch biblios from Directus (HTTP {$httpCode})");
        }

        $data = json_decode($response, true);
        $items = $data['data'] ?? [];

        if (empty($items)) {
            break;
        }

        $all = array_merge($all, $items);

        if ($verbose && count($all) % 10000 === 0) {
            echo "  Fetched {$offset} biblios...\n";
        }

        if (count($items) < $limit) {
            break;
        }

        $offset += $limit;
    }

    return $all;
}

/**
 * Main synchronization logic
 */
function main()
{
    global $argv;
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);

    echo "\n";
    echo "========================================================\n";
    echo "  Koha -> Directus Sync - Holds (Reservations)\n";
    echo "========================================================\n";
    echo "\n";

    if ($verbose) {
        echo "Verbose mode enabled\n\n";
    }

    $startTime = microtime(true);
    $stats = [
        'koha_holds_total' => 0,
        'unique_biblios' => 0,
        'directus_before' => 0,
        'created' => 0,
        'updated' => 0,
        'marked_inactive' => 0,
        'biblios_updated' => 0,
        'errors' => []
    ];

    try {
        // Load configuration
        echo "Loading configuration...\n";
        loadEnv(__DIR__ . '/../.env');

        $config = [
            'OAUTH_URL' => getenv('OAUTH_URL'),
            'CLIENT_ID' => getenv('CLIENT_ID'),
            'CLIENT_SECRET' => getenv('CLIENT_SECRET'),
            'API_BASE_URL' => getenv('API_BASE_URL'),
            'DIRECTUS_API_URL' => getenv('DIRECTUS_API_URL'),
            'DIRECTUS_API_TOKEN' => getenv('DIRECTUS_API_TOKEN')
        ];

        $required = ['OAUTH_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'API_BASE_URL',
                     'DIRECTUS_API_URL', 'DIRECTUS_API_TOKEN'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new Exception("Missing required configuration: {$key}");
            }
        }

        echo "Configuration loaded\n\n";

        // Step 1: Get OAuth token from Koha
        echo "Step 1/6: Getting OAuth token from Koha...\n";
        $kohaToken = getOAuthToken(
            $config['OAUTH_URL'],
            $config['CLIENT_ID'],
            $config['CLIENT_SECRET']
        );

        if (!$kohaToken) {
            throw new Exception("Failed to obtain OAuth token from Koha");
        }

        echo "OAuth token obtained\n\n";

        // Step 2: Fetch all holds from Koha API
        echo "Step 2/6: Fetching holds from Koha API...\n";
        $kohaHolds = fetchAllKohaHolds($config['API_BASE_URL'], $kohaToken);
        $stats['koha_holds_total'] = count($kohaHolds);
        echo "Fetched {$stats['koha_holds_total']} holds from Koha\n\n";

        // Step 3: Aggregate per biblio
        echo "Step 3/6: Aggregating holds per biblio...\n";
        $aggregated = aggregateHoldsByBiblio($kohaHolds);
        $stats['unique_biblios'] = count($aggregated);
        echo "Aggregated to {$stats['unique_biblios']} unique biblios\n";

        if ($verbose && !empty($aggregated)) {
            // Visa topp 5
            $sorted = $aggregated;
            usort($sorted, function ($a, $b) {
                return $b['total_holds'] - $a['total_holds'];
            });
            echo "  Top 5 most reserved:\n";
            $top5 = array_slice($sorted, 0, 5);
            foreach ($top5 as $item) {
                echo "    biblio_id {$item['biblio_id']}: {$item['total_holds']} holds\n";
            }
        }
        echo "\n";

        // Step 4: Fetch item counts from Directus
        echo "Step 4/6: Fetching item counts from Directus...\n";
        $directusClient = new DirectusClient(
            $config['DIRECTUS_API_URL'],
            $config['DIRECTUS_API_TOKEN'],
            $verbose
        );

        $collectionName = 'kft_koha_hold_counts';

        if (!$directusClient->collectionExists($collectionName)) {
            throw new Exception(
                "Collection '{$collectionName}' does not exist in Directus.\n" .
                "Please run 'php create_koha_hold_counts_collection.php' first."
            );
        }

        $itemCounts = fetchItemCountsPerBiblio($directusClient, $verbose);
        echo "Counted items for " . count($itemCounts) . " biblios\n\n";

        // Step 5: Sync to kft_koha_hold_counts
        echo "Step 5/6: Syncing hold counts to Directus...\n";

        $existingRecords = fetchAllDirectusHoldCounts($directusClient, $verbose);
        $stats['directus_before'] = count($existingRecords);
        echo "Found {$stats['directus_before']} existing records in Directus\n";

        // Bygg lookup: biblio_id -> Directus record
        $existingByBiblioId = [];
        foreach ($existingRecords as $record) {
            $existingByBiblioId[$record['biblio_id']] = $record;
        }

        $now = date('Y-m-d H:i:s');

        foreach ($aggregated as $biblioId => $agg) {
            try {
                $directusData = [
                    'biblio_id' => $agg['biblio_id'],
                    'total_holds' => $agg['total_holds'],
                    'holds_waiting' => $agg['holds_waiting'],
                    'holds_ready' => $agg['holds_ready'],
                    'holds_in_transit' => $agg['holds_in_transit'],
                    'item_count' => $itemCounts[$biblioId] ?? 0,
                    'pickup_libraries' => $agg['pickup_libraries'],
                    'oldest_hold_date' => $agg['oldest_hold_date'],
                    'status' => 'active',
                    'last_synced' => $now
                ];

                $existing = $existingByBiblioId[$biblioId] ?? null;

                if ($existing) {
                    $directusClient->updateItem($collectionName, $existing['id'], $directusData);
                    $stats['updated']++;
                    if ($verbose) {
                        echo "  Updated: biblio {$biblioId} ({$agg['total_holds']} holds)\n";
                    }
                } else {
                    $directusClient->createItem($collectionName, $directusData);
                    $stats['created']++;
                    if ($verbose) {
                        echo "  Created: biblio {$biblioId} ({$agg['total_holds']} holds)\n";
                    }
                }

            } catch (Exception $e) {
                $error = "Failed to sync biblio {$biblioId}: " . $e->getMessage();
                $stats['errors'][] = $error;
                echo "  ERROR: {$error}\n";
            }
        }

        // Soft-delete: markera inactive for biblios som inte langre har holds
        foreach ($existingRecords as $existing) {
            $biblioId = $existing['biblio_id'];
            if (!isset($aggregated[$biblioId]) && ($existing['status'] ?? '') === 'active') {
                try {
                    $directusClient->updateItem($collectionName, $existing['id'], [
                        'status' => 'inactive',
                        'total_holds' => 0,
                        'holds_waiting' => 0,
                        'holds_ready' => 0,
                        'holds_in_transit' => 0,
                        'last_synced' => $now
                    ]);
                    $stats['marked_inactive']++;
                    if ($verbose) {
                        echo "  Marked inactive: biblio {$biblioId}\n";
                    }
                } catch (Exception $e) {
                    $stats['errors'][] = "Failed to mark inactive biblio {$biblioId}: " . $e->getMessage();
                }
            }
        }

        echo "Hold counts sync complete\n\n";

        // Step 6: Update hold_count and item_count on kft_koha_biblios
        echo "Step 6/6: Updating hold_count & item_count on kft_koha_biblios...\n";

        $allBiblios = fetchAllDirectusBiblios($directusClient, $verbose);
        echo "Fetched " . count($allBiblios) . " biblios from Directus\n";

        // Bygg hold count map fran aggregerade data
        $holdCountMap = [];
        foreach ($aggregated as $biblioId => $agg) {
            $holdCountMap[$biblioId] = $agg['total_holds'];
        }

        foreach ($allBiblios as $biblio) {
            $biblioId = $biblio['biblio_id'];
            $newHoldCount = $holdCountMap[$biblioId] ?? 0;
            $newItemCount = $itemCounts[$biblioId] ?? 0;
            $oldHoldCount = $biblio['hold_count'] ?? 0;
            $oldItemCount = $biblio['item_count'] ?? 0;

            // Skippa om inget andrats
            if ((int)$newHoldCount === (int)$oldHoldCount && (int)$newItemCount === (int)$oldItemCount) {
                continue;
            }

            try {
                $directusClient->updateItem('kft_koha_biblios', $biblio['id'], [
                    'hold_count' => $newHoldCount,
                    'item_count' => $newItemCount
                ]);
                $stats['biblios_updated']++;

                if ($verbose) {
                    echo "  Updated biblio {$biblioId}: hold_count {$oldHoldCount}->{$newHoldCount}, item_count {$oldItemCount}->{$newItemCount}\n";
                }
            } catch (Exception $e) {
                $stats['errors'][] = "Failed to update biblio {$biblioId} counts: " . $e->getMessage();
            }
        }

        echo "Updated {$stats['biblios_updated']} biblios with hold/item counts\n\n";

    } catch (Exception $e) {
        echo "\nFatal error: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Statistics
    $duration = microtime(true) - $startTime;

    echo "========================================================\n";
    echo "  SYNC STATISTICS\n";
    echo "========================================================\n";
    echo "\n";
    echo "Koha holds total:        {$stats['koha_holds_total']}\n";
    echo "Unique biblios:          {$stats['unique_biblios']}\n";
    echo "Directus before sync:    {$stats['directus_before']}\n";
    echo "--------------------------------------------------------\n";
    echo "Hold counts created:     {$stats['created']}\n";
    echo "Hold counts updated:     {$stats['updated']}\n";
    echo "Marked inactive:         {$stats['marked_inactive']}\n";
    echo "Biblios updated:         {$stats['biblios_updated']}\n";
    echo "Errors:                  " . count($stats['errors']) . "\n";
    echo "--------------------------------------------------------\n";
    echo "Duration:                " . number_format($duration, 2) . "s\n";
    echo "\n";

    if (count($stats['errors']) > 0) {
        echo "Errors:\n";
        foreach ($stats['errors'] as $error) {
            echo "  - {$error}\n";
        }
        echo "\n";
    }

    echo "Sync complete!\n\n";
}

// Run main function
main();
?>
