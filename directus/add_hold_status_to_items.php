#!/usr/bin/env php
<?php
/**
 * Lägg till fältet hold_status på kft_koha_items
 *
 * Engångsmigrering. hold_status sätts av holds-synken (sync_koha_holds.php)
 * och betyder att exemplaret är upptaget av en reservation trots att
 * exemplarfälten (checked_out_date m.fl.) ser lediga ut:
 *   W = väntar på reservationshyllan (Koha reserves.found = W)
 *   T = i transit till upphämtningsfilialen (found = T)
 *   null = ingen aktiv reservation kopplad till exemplaret
 *
 * Items-synken skriver bara sina egna fält och rör inte hold_status.
 *
 * Usage: php add_hold_status_to_items.php
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Hold Counts Sync
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/DirectusClient.php';
require_once __DIR__ . '/../common.php';

loadEnv(__DIR__ . '/../.env');

$config = [
    'DIRECTUS_API_URL' => getenv('DIRECTUS_API_URL'),
    'DIRECTUS_API_TOKEN' => getenv('DIRECTUS_API_TOKEN')
];

if (!$config['DIRECTUS_API_URL'] || !$config['DIRECTUS_API_TOKEN']) {
    die("Error: DIRECTUS_API_URL and DIRECTUS_API_TOKEN must be set in .env\n");
}

echo "Add hold_status to kft_koha_items\n";
echo "==================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_items';

    if (!$client->collectionExists($collectionName)) {
        throw new Exception("Collection '{$collectionName}' does not exist. Run items sync first.");
    }

    $fieldDef = [
        'field' => 'hold_status',
        'type' => 'string',
        'meta' => [
            'interface' => 'input',
            'readonly' => true,
            'hidden' => false,
            'width' => 'quarter',
            'note' => 'W = väntar på reservationshyllan, T = i transit – sätts av holds-synken, null = ingen reservation'
        ],
        'schema' => [
            'max_length' => 1,
            'is_nullable' => true,
            'default_value' => null
        ]
    ];

    echo "Creating field 'hold_status' on {$collectionName}...";
    try {
        $client->createField($collectionName, 'hold_status', $fieldDef);
        echo " OK\n";
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, '400') !== false) {
            echo " ALREADY EXISTS (skipping)\n";
        } else {
            echo " ERROR: {$msg}\n";
            exit(1);
        }
    }

    echo "\nDone. Kör sedan: php sync_koha_holds.php -v\n";

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}
