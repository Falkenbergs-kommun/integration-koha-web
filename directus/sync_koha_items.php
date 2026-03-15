#!/usr/bin/env php
<?php
/**
 * Sync Koha Items (Exemplar) to Directus
 *
 * Fetches all items from Koha API and synchronizes them to
 * the kft_koha_items collection in Directus.
 * ~155k items - uses streaming approach to avoid memory issues:
 *   1. Load Directus lookup-map (only id,item_id,status = small)
 *   2. Stream Koha items in batches, process each immediately
 *   3. Bulk-create new items, update existing ones
 *   4. Soft-delete items no longer in Koha
 *
 * Usage: php sync_koha_items.php [-v|--verbose] [--start-from=N] [--force-update]
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Items Sync
 */

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

/**
 * Transform Koha item data to Directus format
 */
function transformKohaItemToDirectus($kohaItem)
{
    return [
        'item_id'                => $kohaItem['item_id'],
        'biblio_id'              => $kohaItem['biblio_id'] ?? null,
        'item_type_id'           => safeTruncate($kohaItem['item_type_id'] ?? null, 20),
        'effective_item_type_id' => safeTruncate($kohaItem['effective_item_type_id'] ?? null, 20),
        'collection_code'        => safeTruncate($kohaItem['collection_code'] ?? null, 20),
        'callnumber'             => safeTruncate($kohaItem['callnumber'] ?? null, 255),
        'call_number_sort'       => safeTruncate($kohaItem['call_number_sort'] ?? null, 255),
        'home_library_id'        => safeTruncate($kohaItem['home_library_id'] ?? null, 20),
        'holding_library_id'     => safeTruncate($kohaItem['holding_library_id'] ?? null, 20),
        'location'               => safeTruncate($kohaItem['location'] ?? null, 100),
        'permanent_location'     => safeTruncate($kohaItem['permanent_location'] ?? null, 100),
        'checked_out_date'       => $kohaItem['checked_out_date'] ?? null,
        'not_for_loan_status'    => $kohaItem['not_for_loan_status'] ?? 0,
        'damaged_status'         => $kohaItem['damaged_status'] ?? 0,
        'lost_status'            => $kohaItem['lost_status'] ?? 0,
        'withdrawn'              => $kohaItem['withdrawn'] ?? 0,
        'acquisition_date'       => $kohaItem['acquisition_date'] ?? null,
        'purchase_price'         => $kohaItem['purchase_price'] ?? null,
        'checkouts_count'        => $kohaItem['checkouts_count'] ?? 0,
        'last_checkout_date'     => $kohaItem['last_checkout_date'] ?? null,
        'last_seen_date'         => $kohaItem['last_seen_date'] ?? null,
        'koha_timestamp'         => $kohaItem['timestamp'] ?? null,
        'status'                 => 'active',
        'last_synced'            => date('Y-m-d H:i:s')
    ];
}

/**
 * Fetch one batch of items from Koha API using cursor-based pagination.
 *
 * @param string $itemsUrl Base items endpoint URL
 * @param string $token OAuth access token
 * @param int $lastSeenId Cursor: fetch items with item_id > this
 * @param int $perPage Items per batch
 * @return array|null Array of items, or null on failure
 */
function fetchKohaBatch($itemsUrl, $token, $lastSeenId, $perPage = 500)
{
    $result = fetchKohaRequest($itemsUrl, $token, $lastSeenId, $perPage);

    if ($result !== null) {
        return $result;
    }

    // Large batch failed (likely corrupt record). Fall back to small batches
    // to skip past the problematic item(s).
    echo "  Falling back to small batches to skip corrupt records...\n";
    $allItems = [];
    $cursor = $lastSeenId;
    $skipAttempts = 0;
    $maxSkipAttempts = 50; // Safety valve

    while (count($allItems) < $perPage && $skipAttempts < $maxSkipAttempts) {
        $small = fetchKohaRequest($itemsUrl, $token, $cursor, 10);

        if ($small !== null && count($small) > 0) {
            $allItems = array_merge($allItems, $small);
            $cursor = end($small)['item_id'];
            $skipAttempts = 0; // Reset on success
            continue;
        }

        if ($small !== null && count($small) === 0) {
            break; // No more items
        }

        // Batch of 10 failed - try to skip past corrupt range
        // Use binary-style jumps: try +100, then +10, then +1
        $skipped = false;
        foreach ([100, 10, 1] as $jump) {
            $probe = fetchKohaRequest($itemsUrl, $token, $cursor + $jump, 1);
            if ($probe !== null) {
                echo "    Skipped corrupt range after item_id {$cursor} (jumped +{$jump})\n";
                if (count($probe) > 0) {
                    $cursor = $probe[0]['item_id'] - 1; // Position cursor just before the good item
                } else {
                    $cursor += $jump;
                }
                $skipped = true;
                break;
            }
        }

        if (!$skipped) {
            // Even +1 failed, use aggressive jump
            echo "    Skipping corrupt range after item_id {$cursor} (jumping +500)\n";
            $cursor += 500;
        }

        $skipAttempts++;
    }

    return $allItems;
}

/**
 * Single HTTP request to Koha items API with retries.
 *
 * @return array|null Array of items on success, null on persistent failure
 */
function fetchKohaRequest($itemsUrl, $token, $lastSeenId, $perPage)
{
    $maxRetries = 2;
    $qFilter = json_encode(['item_id' => ['>' => $lastSeenId]]);
    $url = "{$itemsUrl}?_order_by=item_id&_per_page={$perPage}&q=" . urlencode($qFilter);

    for ($retry = 0; $retry < $maxRetries; $retry++) {
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
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $items = json_decode($response, true);
            if (is_array($items)) {
                return $items;
            }
        }

        if ($retry < $maxRetries - 1) {
            sleep(2);
        }
    }

    return null;
}

/**
 * Fetch ALL items from a Directus collection using offset pagination.
 * Only fetches minimal fields (id, item_id, status) to keep memory low.
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

        foreach ($items as $item) {
            $all[] = $item;
        }

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
    global $argv;
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);

    // Parse --start-from parameter (item_id to resume from)
    $startFromId = 0;
    $forceUpdate = in_array('--force-update', $argv);
    foreach ($argv as $arg) {
        if (preg_match('/^--start-from=(\d+)$/', $arg, $matches)) {
            $startFromId = (int)$matches[1];
        }
    }

    echo "\n";
    echo "========================================================\n";
    echo "  Koha -> Directus Sync - Items (Exemplar)\n";
    echo "========================================================\n";
    echo "\n";

    if ($verbose) {
        echo "Verbose mode enabled\n";
    }
    if ($startFromId > 0) {
        echo "Starting from item_id > {$startFromId}\n";
    }
    if ($forceUpdate) {
        echo "Force update: will update ALL items regardless of timestamp\n";
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

        // Step 1: Fetch existing items from Directus (lightweight: only id,item_id,status)
        // NOTE: This is done BEFORE Koha OAuth to avoid token expiration during long Directus load
        echo "Step 1/5: Fetching existing items from Directus...\n";
        $directusClient = new DirectusClient(
            $config['DIRECTUS_API_URL'],
            $config['DIRECTUS_API_TOKEN'],
            $verbose
        );

        $collectionName = 'kft_koha_items';

        if (!$directusClient->collectionExists($collectionName)) {
            throw new Exception(
                "Collection '{$collectionName}' does not exist in Directus.\n" .
                "Please run 'php create_koha_items_collection.php' first."
            );
        }

        $existingItems = fetchAllDirectusItems(
            $config['DIRECTUS_API_URL'],
            $config['DIRECTUS_API_TOKEN'],
            $collectionName,
            'id,item_id,status,koha_timestamp'
        );
        $stats['directus_before'] = count($existingItems);

        echo "Found {$stats['directus_before']} existing items in Directus\n\n";

        // Build lookup map: item_id -> Directus record
        $existingById = [];
        $duplicateIdsToDelete = [];

        foreach ($existingItems as $item) {
            $itemId = $item['item_id'];
            if (isset($existingById[$itemId])) {
                $duplicateIdsToDelete[] = $existingById[$itemId]['id'];
            }
            $existingById[$itemId] = $item;
        }

        // Free the raw array - we only need the lookup map
        unset($existingItems);

        if (count($duplicateIdsToDelete) > 0) {
            echo "  Found " . count($duplicateIdsToDelete) . " duplicate records - will clean up after sync\n";
        }

        // Track which Koha item IDs we've seen (for soft-delete check)
        $seenKohaIds = [];

        // Step 2: Get OAuth token from Koha (done right before API calls to avoid expiry)
        echo "Step 2/5: Getting OAuth token from Koha...\n";
        $kohaToken = getOAuthToken(
            $config['OAUTH_URL'],
            $config['CLIENT_ID'],
            $config['CLIENT_SECRET']
        );

        if (!$kohaToken) {
            throw new Exception("Failed to obtain OAuth token from Koha");
        }

        echo "OAuth token obtained\n\n";

        // Step 3/5: Stream Koha items and sync to Directus in batches
        echo "Step 3/5: Streaming items from Koha API and syncing...\n";

        $baseApiUrl = preg_replace('#/biblios/?$#', '', $config['API_BASE_URL']);
        $itemsUrl = rtrim($baseApiUrl, '/') . '/items';
        $lastSeenId = $startFromId;
        $batchNum = 0;
        $perPage = 500;
        $tokenTime = time();

        while (true) {
            $batchNum++;

            // Refresh OAuth token every 45 minutes to prevent expiry
            if (time() - $tokenTime > 2700) {
                echo "  Refreshing OAuth token...\n";
                $kohaToken = getOAuthToken(
                    $config['OAUTH_URL'],
                    $config['CLIENT_ID'],
                    $config['CLIENT_SECRET']
                );
                if (!$kohaToken) {
                    throw new Exception("Failed to refresh OAuth token");
                }
                $tokenTime = time();
            }

            // Fetch one batch from Koha
            $kohaBatch = fetchKohaBatch($itemsUrl, $kohaToken, $lastSeenId, $perPage);

            if (count($kohaBatch) === 0) {
                break;
            }

            // Update cursor
            $lastSeenId = $kohaBatch[count($kohaBatch) - 1]['item_id'];
            $stats['koha_total'] += count($kohaBatch);

            echo "  Koha batch {$batchNum}: " . count($kohaBatch) . " items (total: {$stats['koha_total']}, last_id: {$lastSeenId})\n";

            // Separate new vs existing
            $toCreate = [];
            $toUpdate = [];

            foreach ($kohaBatch as $kohaItem) {
                $itemId = $kohaItem['item_id'];
                $seenKohaIds[$itemId] = true;

                try {
                    $existing = $existingById[$itemId] ?? null;

                    if ($existing && !$forceUpdate) {
                        // Skip update if koha_timestamp hasn't changed
                        // Formats differ: Koha uses +01:00, Directus uses .000Z (UTC)
                        // Compare as unix timestamps to normalize
                        $kohaTs = $kohaItem['timestamp'] ?? null;
                        $directusTs = $existing['koha_timestamp'] ?? null;

                        if ($kohaTs !== null && $directusTs !== null
                            && strtotime($kohaTs) === strtotime($directusTs)) {
                            $stats['skipped']++;
                            continue;
                        }
                    }

                    $directusData = transformKohaItemToDirectus($kohaItem);

                    if ($existing) {
                        $toUpdate[] = [
                            'directus_id' => $existing['id'],
                            'data' => $directusData
                        ];
                    } else {
                        $toCreate[] = $directusData;
                    }
                } catch (Exception $e) {
                    $stats['errors'][] = "Transform item {$itemId}: " . $e->getMessage();
                }
            }

            // Bulk-create new items for this batch
            if (count($toCreate) > 0) {
                try {
                    $directusClient->createItems($collectionName, $toCreate);
                    $stats['created'] += count($toCreate);
                } catch (Exception $e) {
                    echo "    Bulk create failed, falling back to individual: " . $e->getMessage() . "\n";
                    foreach ($toCreate as $item) {
                        try {
                            $directusClient->createItem($collectionName, $item);
                            $stats['created']++;
                        } catch (Exception $e2) {
                            $stats['errors'][] = "Create item {$item['item_id']}: " . $e2->getMessage();
                        }
                    }
                }
            }

            // Update existing items for this batch
            foreach ($toUpdate as $update) {
                try {
                    $directusClient->updateItem($collectionName, $update['directus_id'], $update['data']);
                    $stats['updated']++;
                } catch (Exception $e) {
                    $stats['errors'][] = "Update item: " . $e->getMessage();
                }
            }

            // Free batch memory
            unset($kohaBatch, $toCreate, $toUpdate);

            // Progress summary every 10 batches
            if ($batchNum % 10 === 0) {
                $mem = round(memory_get_usage(true) / 1024 / 1024);
                echo "    Progress: {$stats['koha_total']} processed, {$stats['created']} created, {$stats['updated']} updated, {$stats['skipped']} skipped (unchanged), {$mem}MB RAM\n";
            }

            if ($stats['koha_total'] - (($batchNum - 1) * $perPage + count($kohaBatch ?? [])) < $perPage && count($kohaBatch ?? []) < $perPage) {
                break;
            }
        }

        echo "Streaming sync complete\n\n";

        // Step 4: Delete duplicate records
        if (count($duplicateIdsToDelete) > 0) {
            echo "Step 4/5: Deleting " . count($duplicateIdsToDelete) . " duplicate records...\n";
            $deleteBatches = array_chunk($duplicateIdsToDelete, 200);
            $deletedCount = 0;

            foreach ($deleteBatches as $batchIdx => $batch) {
                try {
                    $directusClient->deleteItems($collectionName, $batch);
                    $deletedCount += count($batch);
                } catch (Exception $e) {
                    $stats['errors'][] = "Delete duplicates: " . $e->getMessage();
                }
            }

            $stats['duplicates_deleted'] = $deletedCount;
            echo "Deleted {$deletedCount} duplicates\n\n";
        } else {
            echo "Step 4/5: No duplicates to clean up\n\n";
        }

        // Step 5: Soft-delete items no longer in Koha
        // Skip when using --start-from since we only synced a partial range
        if ($startFromId > 0) {
            echo "Step 5/5: Skipping soft-delete (partial sync with --start-from)\n\n";
        } else {
            echo "Step 5/5: Marking inactive items...\n";

            $deletedIdSet = array_flip($duplicateIdsToDelete);

            foreach ($existingById as $itemId => $existing) {
                if (isset($deletedIdSet[$existing['id']])) {
                    continue;
                }

                if (!isset($seenKohaIds[$itemId]) && ($existing['status'] ?? '') === 'active') {
                    try {
                        $directusClient->updateItem($collectionName, $existing['id'], [
                            'status' => 'inactive',
                            'last_synced' => date('Y-m-d H:i:s')
                        ]);
                        $stats['marked_inactive']++;

                        if ($stats['marked_inactive'] % 100 === 0) {
                            echo "  Marked inactive: {$stats['marked_inactive']}\n";
                        }
                    } catch (Exception $e) {
                        $stats['errors'][] = "Mark inactive {$itemId}: " . $e->getMessage();
                    }
                }
            }

            echo "Soft delete complete ({$stats['marked_inactive']} marked)\n\n";
        }

    } catch (Exception $e) {
        echo "\nFatal error: " . $e->getMessage() . "\n";
        exit(1);
    }

    // Statistics
    $duration = microtime(true) - $startTime;
    $mem = round(memory_get_peak_usage(true) / 1024 / 1024);

    echo "\n";
    echo "========================================================\n";
    echo "  SYNC STATISTICS\n";
    echo "========================================================\n";
    echo "\n";
    echo "Koha items:              {$stats['koha_total']}\n";
    echo "Directus before sync:    {$stats['directus_before']}\n";
    echo "--------------------------------------------------------\n";
    echo "Created:                 {$stats['created']}\n";
    echo "Updated:                 {$stats['updated']}\n";
    echo "Skipped (unchanged):     {$stats['skipped']}\n";
    echo "Marked inactive:         {$stats['marked_inactive']}\n";
    echo "Duplicates deleted:      {$stats['duplicates_deleted']}\n";
    echo "Errors:                  " . count($stats['errors']) . "\n";
    echo "--------------------------------------------------------\n";

    $totalActive = $stats['directus_before'] + $stats['created'] - $stats['marked_inactive'] - $stats['duplicates_deleted'];
    echo "Total in Directus now:   ~{$totalActive}\n";
    echo "Peak memory:             {$mem}MB\n";
    echo "Duration:                " . number_format($duration, 2) . "s\n";
    echo "\n";

    if (count($stats['errors']) > 0) {
        echo "Errors (showing first 20):\n";
        $showErrors = array_slice($stats['errors'], 0, 20);
        foreach ($showErrors as $error) {
            echo "  - {$error}\n";
        }
        if (count($stats['errors']) > 20) {
            echo "  ... and " . (count($stats['errors']) - 20) . " more\n";
        }
        echo "\n";
    }

    echo "Sync complete!\n\n";
}

// Run main function
main();
?>
