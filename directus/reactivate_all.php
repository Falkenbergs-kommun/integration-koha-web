#!/usr/bin/env php
<?php
/**
 * Reactivate all biblios that were incorrectly marked inactive.
 */

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

loadEnv(__DIR__ . '/../.env');
$directusUrl = getenv('DIRECTUS_API_URL');
$directusToken = getenv('DIRECTUS_API_TOKEN');

$client = new DirectusClient($directusUrl, $directusToken);

echo "Fetching inactive biblios...\n";

// Fetch all and filter in PHP (API token may not have access to status filter)
$all = $client->getItems('kft_koha_biblios', [], 20000);
$inactive = array_filter($all, fn($item) => ($item['status'] ?? '') === 'inactive');
$count = count($inactive);

echo "Found {$count} inactive biblios. Reactivating...\n\n";

if ($count === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

$done = 0;
$errors = 0;

foreach ($inactive as $item) {
    try {
        $client->updateItem('kft_koha_biblios', $item['id'], ['status' => 'active']);
        $done++;
        if ($done % 100 === 0) {
            echo "  Reactivated {$done}/{$count}...\n";
        }
    } catch (Exception $e) {
        $errors++;
        echo "  ERROR on id {$item['id']}: " . $e->getMessage() . "\n";
    }
}

echo "\n✅ Done! {$done}/{$count} biblios reactivated.";
if ($errors > 0) {
    echo " ({$errors} errors)";
}
echo "\n";
