#!/usr/bin/env php
<?php
/**
 * Add New Fields to Existing kft_koha_biblios Collection
 *
 * This script adds the newly discovered metadata fields (creation_date,
 * koha_timestamp, etc.) to the existing collection without losing data.
 *
 * Usage: php add_new_fields.php
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

echo "📚 Adding New Fields to kft_koha_biblios Collection\n";
echo "====================================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_biblios';

    // Check if collection exists
    if (!$client->collectionExists($collectionName)) {
        die("❌ Collection '{$collectionName}' does not exist!\n");
    }

    echo "✅ Collection '{$collectionName}' found.\n\n";

    // Define new fields to add
    $newFields = [
        [
            'field' => 'issn',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'creation_date',
            'type' => 'date',
            'meta' => [
                'interface' => 'datetime',
                'readonly' => true,
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
                'readonly' => true,
                'width' => 'half',
                'display' => 'datetime',
                'display_options' => ['relative' => true]
            ],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'copyright_date',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'lc_control_number',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 100]
        ],
        [
            'field' => 'serial',
            'type' => 'boolean',
            'meta' => [
                'interface' => 'boolean',
                'width' => 'quarter'
            ],
            'schema' => [
                'is_nullable' => true,
                'default_value' => false
            ]
        ]
    ];

    // Add each field
    echo "📝 Adding " . count($newFields) . " new fields...\n";
    $added = 0;
    $skipped = 0;

    foreach ($newFields as $index => $fieldDef) {
        $fieldName = $fieldDef['field'];
        echo "  [" . ($index + 1) . "/" . count($newFields) . "] Adding field '{$fieldName}'...";

        try {
            $client->createField($collectionName, $fieldName, $fieldDef);
            echo " ✅\n";
            $added++;
        } catch (Exception $e) {
            // Field might already exist
            if (strpos($e->getMessage(), 'already exists') !== false || strpos($e->getMessage(), 'duplicate') !== false) {
                echo " ⚠️  Already exists\n";
                $skipped++;
            } else {
                echo " ❌ Error: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n✨ Migration complete!\n";
    echo "📊 Added: {$added} fields\n";
    echo "⚠️  Skipped: {$skipped} fields (already existed)\n";
    echo "🔗 View at: {$config['DIRECTUS_API_URL']}/admin/content/{$collectionName}\n\n";

    echo "📌 Next step: Run sync to populate these fields:\n";
    echo "   php sync_koha_to_directus.php -v\n\n";

} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
