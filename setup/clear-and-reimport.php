#!/usr/bin/env php
<?php
/**
 * Clear all enriched data and re-import with correct JSON structure
 * This fixes the issue where JSON fields were stored as strings
 */

require_once __DIR__ . '/common.php';
loadEnv(__DIR__ . '/.env');

$apiUrl = getenv('DIRECTUS_API_URL');
$apiToken = getenv('DIRECTUS_API_TOKEN');

if (!$apiUrl || !$apiToken) {
    die("ERROR: Missing DIRECTUS_API_URL or DIRECTUS_API_TOKEN\n");
}

echo "===========================================\n";
echo "Clear and Re-import Enriched Data\n";
echo "===========================================\n\n";

// Step 1: Get all existing records
echo "[1/3] Fetching existing records...\n";
$ch = curl_init("$apiUrl/items/kft_koha_enriched?limit=1000");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiToken
]);
$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);

$existingRecords = $data['data'] ?? [];
echo "  Found " . count($existingRecords) . " records\n\n";

// Step 2: Delete all records
echo "[2/3] Deleting existing records...\n";
foreach ($existingRecords as $record) {
    $id = $record['id'];
    echo "  Deleting record ID $id...";

    $ch = curl_init("$apiUrl/items/kft_koha_enriched/$id");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiToken
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        echo " ✓\n";
    } else {
        echo " ✗ (HTTP $httpCode)\n";
    }
}

echo "\n[3/3] Re-importing with correct structure...\n";
echo "Running import script...\n\n";

// Run the import script
passthru('php ' . __DIR__ . '/docs/import-enriched-data.php', $returnCode);

if ($returnCode === 0) {
    echo "\n✓ Re-import completed successfully!\n";
} else {
    echo "\n✗ Re-import failed with code $returnCode\n";
}

echo "\n===========================================\n";
echo "Done! Check Directus UI to verify JSON fields work correctly.\n";
echo "===========================================\n";
