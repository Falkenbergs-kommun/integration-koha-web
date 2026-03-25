#!/usr/bin/env php
<?php
/**
 * Create Directus Collection for Koha Hold Counts
 *
 * This script creates the 'kft_koha_hold_counts' collection in Directus
 * with fields for aggregated reservation data per biblio.
 * Stores counts only — no patron data (GDPR).
 *
 * Usage: php create_koha_hold_counts_collection.php
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

echo "Koha Hold Counts -> Directus Collection Creator\n";
echo "=================================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_hold_counts';

    // Check if collection already exists
    if ($client->collectionExists($collectionName)) {
        echo "Collection '{$collectionName}' already exists!\n";
        echo "Do you want to delete and recreate it? (y/N): ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));

        if (strtolower($line) === 'y') {
            echo "Deleting existing collection...\n";
            $client->deleteCollection($collectionName);
            echo "Collection deleted.\n\n";
        } else {
            echo "Aborting...\n";
            exit(0);
        }
    }

    // Create collection with custom meta
    echo "Creating collection '{$collectionName}'...\n";

    $url = rtrim($config['DIRECTUS_API_URL'], '/') . '/collections';
    $collectionData = [
        'collection' => $collectionName,
        'meta' => [
            'collection' => $collectionName,
            'icon' => 'local_fire_department',
            'note' => 'Koha reservationer aggregerade per biblio - Synkroniserad fran Koha API',
            'display_template' => '{{biblio_id}} – {{total_holds}} holds',
            'hidden' => false,
            'singleton' => false
        ],
        'schema' => [
            'name' => $collectionName
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($collectionData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['DIRECTUS_API_TOKEN']
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception("Failed to create collection (HTTP {$httpCode}): {$response}");
    }

    echo "Collection created!\n\n";

    // Get field definitions
    $fields = getFieldDefinitions();

    // Create each field
    echo "Creating " . count($fields) . " fields...\n";
    foreach ($fields as $index => $fieldDef) {
        $fieldName = $fieldDef['field'];
        echo "  [" . ($index + 1) . "/" . count($fields) . "] Creating field '{$fieldName}'...";

        try {
            $client->createField($collectionName, $fieldName, $fieldDef);
            echo " OK\n";
        } catch (Exception $e) {
            echo " ERROR: " . $e->getMessage() . "\n";
        }
    }

    echo "\nCollection setup complete!\n";
    echo "Collection: {$collectionName}\n";
    echo "Fields: " . count($fields) . "\n";
    echo "View at: {$config['DIRECTUS_API_URL']}/admin/content/{$collectionName}\n";

} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Get field definitions for the kft_koha_hold_counts collection
 */
function getFieldDefinitions()
{
    return [
        // Koha biblio_id (unique, FK till biblios)
        [
            'field' => 'biblio_id',
            'type' => 'integer',
            'meta' => [
                'interface' => 'input',
                'readonly' => true,
                'hidden' => false,
                'width' => 'half'
            ],
            'schema' => [
                'is_nullable' => false,
                'is_unique' => true
            ]
        ],

        // Totalt antal aktiva holds
        [
            'field' => 'total_holds',
            'type' => 'integer',
            'meta' => ['interface' => 'input', 'readonly' => true, 'width' => 'quarter'],
            'schema' => ['is_nullable' => false, 'default_value' => 0]
        ],

        // Holds som koar (status null)
        [
            'field' => 'holds_waiting',
            'type' => 'integer',
            'meta' => ['interface' => 'input', 'readonly' => true, 'width' => 'quarter'],
            'schema' => ['is_nullable' => false, 'default_value' => 0]
        ],

        // Holds redo for hamtning (status W)
        [
            'field' => 'holds_ready',
            'type' => 'integer',
            'meta' => ['interface' => 'input', 'readonly' => true, 'width' => 'quarter'],
            'schema' => ['is_nullable' => false, 'default_value' => 0]
        ],

        // Holds i transit (status T)
        [
            'field' => 'holds_in_transit',
            'type' => 'integer',
            'meta' => ['interface' => 'input', 'readonly' => true, 'width' => 'quarter'],
            'schema' => ['is_nullable' => false, 'default_value' => 0]
        ],

        // Antal exemplar av titeln (fran kft_koha_items)
        [
            'field' => 'item_count',
            'type' => 'integer',
            'meta' => ['interface' => 'input', 'readonly' => true, 'width' => 'quarter'],
            'schema' => ['is_nullable' => false, 'default_value' => 0]
        ],

        // Uphamtningsbibliotek med antal per filial
        [
            'field' => 'pickup_libraries',
            'type' => 'json',
            'meta' => [
                'interface' => 'input-code',
                'options' => ['language' => 'JSON'],
                'readonly' => true,
                'width' => 'full'
            ],
            'schema' => ['is_nullable' => true]
        ],

        // Aldsta reservationsdatum
        [
            'field' => 'oldest_hold_date',
            'type' => 'date',
            'meta' => [
                'interface' => 'datetime',
                'readonly' => true,
                'width' => 'quarter'
            ],
            'schema' => ['is_nullable' => true]
        ],

        // Status (active/inactive)
        [
            'field' => 'status',
            'type' => 'string',
            'meta' => [
                'interface' => 'select-dropdown',
                'options' => [
                    'choices' => [
                        ['text' => 'Active', 'value' => 'active'],
                        ['text' => 'Inactive', 'value' => 'inactive']
                    ]
                ],
                'display' => 'labels',
                'display_options' => [
                    'choices' => [
                        ['text' => 'Active', 'value' => 'active', 'foreground' => '#FFFFFF', 'background' => '#00C897'],
                        ['text' => 'Inactive', 'value' => 'inactive', 'foreground' => '#FFFFFF', 'background' => '#A2B5CD']
                    ]
                ],
                'required' => true,
                'width' => 'half'
            ],
            'schema' => [
                'default_value' => 'active',
                'is_nullable' => false
            ]
        ],

        // Senaste synktidpunkt
        [
            'field' => 'last_synced',
            'type' => 'timestamp',
            'meta' => [
                'interface' => 'datetime',
                'readonly' => true,
                'width' => 'half'
            ],
            'schema' => ['is_nullable' => true]
        ]
    ];
}
?>
