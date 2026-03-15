#!/usr/bin/env php
<?php
/**
 * Sync Koha Libraries (Branches) to Directus
 *
 * Fetches all libraries from Koha API and synchronizes them
 * to the kft_koha_branches collection in Directus.
 * Only ~11 libraries, so no pagination needed.
 *
 * Usage: php sync_koha_branches.php [-v|--verbose]
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Branches Sync
 */

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

/**
 * Transform Koha library data to Directus format
 *
 * @param array $kohaLibrary Koha API library data
 * @return array Directus-formatted data
 */
function transformKohaLibraryToDirectus($kohaLibrary)
{
    return [
        'library_id'      => $kohaLibrary['library_id'],
        'name'            => safeTruncate($kohaLibrary['name'] ?? null, 255),
        'address1'        => safeTruncate($kohaLibrary['address1'] ?? null, 255),
        'address2'        => safeTruncate($kohaLibrary['address2'] ?? null, 255),
        'address3'        => safeTruncate($kohaLibrary['address3'] ?? null, 255),
        'city'            => safeTruncate($kohaLibrary['city'] ?? null, 100),
        'postal_code'     => safeTruncate($kohaLibrary['postal_code'] ?? null, 20),
        'country'         => safeTruncate($kohaLibrary['country'] ?? null, 100),
        'phone'           => safeTruncate($kohaLibrary['phone'] ?? null, 50),
        'email'           => safeTruncate($kohaLibrary['email'] ?? null, 255),
        'url'             => safeTruncate($kohaLibrary['url'] ?? null, 500),
        'pickup_location' => $kohaLibrary['pickup_location'] ?? false,
        'is_public'       => $kohaLibrary['public'] ?? true,
        'status'          => 'active',
        'last_synced'     => date('Y-m-d H:i:s')
    ];
}

/**
 * Fetch all libraries from Koha API
 *
 * @param string $apiBaseUrl Base URL for Koha API (points to /biblios/)
 * @param string $token OAuth access token
 * @return array Array of library objects
 */
function fetchKohaLibraries($apiBaseUrl, $token)
{
    // API_BASE_URL pekar till /biblios/, vi behover /libraries
    $baseApiUrl = preg_replace('#/biblios/?$#', '', $apiBaseUrl);
    $url = rtrim($baseApiUrl, '/') . '/libraries';

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
        throw new Exception("Failed to fetch libraries from Koha API (HTTP {$httpCode}): {$error}");
    }

    $libraries = json_decode($response, true);

    if (!is_array($libraries)) {
        throw new Exception("Invalid API response: expected array of libraries");
    }

    return $libraries;
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
    echo "  Koha -> Directus Sync - Branches (Libraries)\n";
    echo "========================================================\n";
    echo "\n";

    if ($verbose) {
        echo "Verbose mode enabled\n\n";
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
        echo "Step 1/4: Getting OAuth token from Koha...\n";
        $kohaToken = getOAuthToken(
            $config['OAUTH_URL'],
            $config['CLIENT_ID'],
            $config['CLIENT_SECRET']
        );

        if (!$kohaToken) {
            throw new Exception("Failed to obtain OAuth token from Koha");
        }

        echo "OAuth token obtained\n\n";

        // Step 2: Fetch all libraries from Koha API
        echo "Step 2/4: Fetching libraries from Koha API...\n";
        $kohaLibraries = fetchKohaLibraries($config['API_BASE_URL'], $kohaToken);
        $stats['koha_total'] = count($kohaLibraries);
        echo "Fetched {$stats['koha_total']} libraries from Koha\n\n";

        // Step 3: Fetch existing branches from Directus
        echo "Step 3/4: Fetching existing branches from Directus...\n";
        $directusClient = new DirectusClient(
            $config['DIRECTUS_API_URL'],
            $config['DIRECTUS_API_TOKEN'],
            $verbose
        );

        $collectionName = 'kft_koha_branches';

        if (!$directusClient->collectionExists($collectionName)) {
            throw new Exception(
                "Collection '{$collectionName}' does not exist in Directus.\n" .
                "Please run 'php create_koha_branches_collection.php' first."
            );
        }

        // Small collection, single fetch is fine
        $existingResult = $directusClient->getItems($collectionName, [], 1000);
        $existingBranches = $existingResult['data'] ?? [];
        $stats['directus_before'] = count($existingBranches);

        echo "Found {$stats['directus_before']} existing branches in Directus\n\n";

        // Build lookup map: library_id -> Directus record
        $existingByLibraryId = [];
        foreach ($existingBranches as $branch) {
            $existingByLibraryId[$branch['library_id']] = $branch;
        }

        // Collect all Koha library IDs for soft-delete check
        $kohaLibraryIds = [];
        foreach ($kohaLibraries as $lib) {
            $kohaLibraryIds[$lib['library_id']] = true;
        }

        // Step 4: Sync libraries (create/update)
        echo "Step 4/4: Synchronizing libraries...\n";

        foreach ($kohaLibraries as $kohaLibrary) {
            $libraryId = $kohaLibrary['library_id'];

            try {
                $directusData = transformKohaLibraryToDirectus($kohaLibrary);
                $existing = $existingByLibraryId[$libraryId] ?? null;

                if ($existing) {
                    $directusClient->updateItem($collectionName, $existing['id'], $directusData);
                    $stats['updated']++;
                    if ($verbose) {
                        echo "  Updated: {$libraryId} ({$kohaLibrary['name']})\n";
                    }
                } else {
                    $directusClient->createItem($collectionName, $directusData);
                    $stats['created']++;
                    if ($verbose) {
                        echo "  Created: {$libraryId} ({$kohaLibrary['name']})\n";
                    }
                }

            } catch (Exception $e) {
                $error = "Failed to sync {$libraryId}: " . $e->getMessage();
                $stats['errors'][] = $error;
                echo "  ERROR: {$error}\n";
            }
        }

        // Soft-delete: mark branches inactive if no longer in Koha
        foreach ($existingBranches as $existing) {
            $libraryId = $existing['library_id'];
            if (!isset($kohaLibraryIds[$libraryId]) && ($existing['status'] ?? '') === 'active') {
                try {
                    $directusClient->updateItem($collectionName, $existing['id'], [
                        'status' => 'inactive',
                        'last_synced' => date('Y-m-d H:i:s')
                    ]);
                    $stats['marked_inactive']++;
                    echo "  Marked inactive: {$libraryId}\n";
                } catch (Exception $e) {
                    $stats['errors'][] = "Failed to mark inactive {$libraryId}: " . $e->getMessage();
                }
            }
        }

        echo "Synchronization complete\n\n";

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
    echo "Koha libraries:          {$stats['koha_total']}\n";
    echo "Directus before sync:    {$stats['directus_before']}\n";
    echo "--------------------------------------------------------\n";
    echo "Created:                 {$stats['created']}\n";
    echo "Updated:                 {$stats['updated']}\n";
    echo "Marked inactive:         {$stats['marked_inactive']}\n";
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
