#!/usr/bin/env php
<?php
/**
 * Fix Read-Only Fields
 *
 * This script removes the 'readonly' flag from creation_date and koha_timestamp
 * fields so they can be updated via API.
 *
 * Usage: php fix_readonly_fields.php
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Biblios Sync
 */

// Enable error reporting for CLI
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/DirectusClient.php';
require_once __DIR__ . '/../common.php';

// Load environment variables
loadEnv(__DIR__ . '/../.env');

$config = [
    'DIRECTUS_API_URL' => getenv('DIRECTUS_API_URL'),
    'DIRECTUS_API_TOKEN' => getenv('DIRECTUS_API_TOKEN')
];

if (!$config['DIRECTUS_API_URL'] || !$config['DIRECTUS_API_TOKEN']) {
    die("❌ Error: DIRECTUS_API_URL and DIRECTUS_API_TOKEN must be set in .env\n");
}

echo "🔧 Fixing Read-Only Fields\n";
echo "====================================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_biblios';

    // Check if collection exists
    if (!$client->collectionExists($collectionName)) {
        die("❌ Collection '{$collectionName}' does not exist!\n");
    }

    echo "✅ Collection '{$collectionName}' found.\n\n";

    // Fields to update
    $fieldsToFix = [
        [
            'field' => 'creation_date',
            'type' => 'date',
            'meta' => [
                'interface' => 'datetime',
                'readonly' => false,  // Changed from true to false
                'width' => 'half',
                'display' => 'datetime',
                'display_options' => ['relative' => true]
            ],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'koha_timestamp',
            'type' => 'timestamp',
            'meta' => [
                'interface' => 'datetime',
                'readonly' => false,  // Changed from true to false
                'width' => 'half',
                'display' => 'datetime',
                'display_options' => ['relative' => true]
            ],
            'schema' => ['is_nullable' => true]
        ]
    ];

    // Update each field
    echo "📝 Updating " . count($fieldsToFix) . " fields...\n";

    foreach ($fieldsToFix as $index => $fieldDef) {
        $fieldName = $fieldDef['field'];
        echo "  [" . ($index + 1) . "/" . count($fieldsToFix) . "] Updating field '{$fieldName}'...";

        try {
            // Update field using PATCH
            $url = rtrim($config['DIRECTUS_API_URL'], '/') . "/fields/{$collectionName}/{$fieldName}";

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $config['DIRECTUS_API_TOKEN']
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fieldDef));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                echo " ✅\n";
            } else {
                echo " ⚠️  HTTP {$httpCode}: {$response}\n";
            }

        } catch (Exception $e) {
            echo " ❌ Error: " . $e->getMessage() . "\n";
        }
    }

    echo "\n✨ Fields updated!\n";
    echo "🔗 View at: {$config['DIRECTUS_API_URL']}/admin/settings/data-model/{$collectionName}\n\n";

    echo "📌 Now you can run the backfill script again:\n";
    echo "   php backfill_new_fields.php\n\n";

} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
