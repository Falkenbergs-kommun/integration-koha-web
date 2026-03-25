#!/usr/bin/env php
<?php
/**
 * Add hold_count and item_count fields to kft_koha_biblios
 *
 * One-time migration script that adds two readonly integer fields
 * to the existing biblios collection. These are updated by the
 * holds sync script, not by the biblios sync.
 *
 * Usage: php add_hold_count_to_biblios.php
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Hold Counts Sync
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
    die("Error: DIRECTUS_API_URL and DIRECTUS_API_TOKEN must be set in .env\n");
}

echo "Add hold_count & item_count to kft_koha_biblios\n";
echo "=================================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_biblios';

    if (!$client->collectionExists($collectionName)) {
        throw new Exception("Collection '{$collectionName}' does not exist. Run biblios sync first.");
    }

    $fields = [
        [
            'field' => 'hold_count',
            'type' => 'integer',
            'meta' => [
                'interface' => 'input',
                'readonly' => true,
                'hidden' => false,
                'width' => 'quarter',
                'note' => 'Antal aktiva reservationer - uppdateras av holds-synken'
            ],
            'schema' => [
                'is_nullable' => true,
                'default_value' => 0
            ]
        ],
        [
            'field' => 'item_count',
            'type' => 'integer',
            'meta' => [
                'interface' => 'input',
                'readonly' => true,
                'hidden' => false,
                'width' => 'quarter',
                'note' => 'Antal exemplar - uppdateras av holds-synken'
            ],
            'schema' => [
                'is_nullable' => true,
                'default_value' => 0
            ]
        ]
    ];

    foreach ($fields as $fieldDef) {
        $fieldName = $fieldDef['field'];
        echo "Creating field '{$fieldName}' on {$collectionName}...";

        try {
            $client->createField($collectionName, $fieldName, $fieldDef);
            echo " OK\n";
        } catch (Exception $e) {
            $msg = $e->getMessage();
            // Field might already exist
            if (strpos($msg, '400') !== false) {
                echo " ALREADY EXISTS (skipping)\n";
            } else {
                echo " ERROR: {$msg}\n";
            }
        }
    }

    echo "\nDone! Fields added to {$collectionName}.\n";
    echo "These fields are owned by the holds sync — biblios sync will not touch them.\n";

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
