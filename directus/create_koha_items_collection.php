#!/usr/bin/env php
<?php
/**
 * Create Directus Collection for Koha Items (Exemplar)
 *
 * This script creates the 'kft_koha_items' collection in Directus
 * with fields for storing item/exemplar data from Koha API.
 *
 * Usage: php create_koha_items_collection.php
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Items Sync
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

echo "Koha Items -> Directus Collection Creator\n";
echo "==========================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_items';

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
            'icon' => 'inventory_2',
            'note' => 'Koha exemplar (items) - Synkroniserad fran Koha API',
            'display_template' => '{{item_id}} – {{callnumber}}',
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
 * Get field definitions for the kft_koha_items collection
 */
function getFieldDefinitions()
{
    return [
        // Koha item_id (unique, not PK - Directus auto-creates id as PK)
        [
            'field' => 'item_id',
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

        // FK to biblios (plain integer, no Directus M2O relation)
        [
            'field' => 'biblio_id',
            'type' => 'integer',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true]
        ],

        // Item type fields
        [
            'field' => 'item_type_id',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 20]
        ],
        [
            'field' => 'effective_item_type_id',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 20]
        ],

        // Collection and call number
        [
            'field' => 'collection_code',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 20]
        ],
        [
            'field' => 'callnumber',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'call_number_sort',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],

        // Library/branch references (plain strings, no Directus M2O)
        [
            'field' => 'home_library_id',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 20]
        ],
        [
            'field' => 'holding_library_id',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 20]
        ],

        // Location
        [
            'field' => 'location',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 100]
        ],
        [
            'field' => 'permanent_location',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 100]
        ],

        // Availability / loan status
        [
            'field' => 'checked_out_date',
            'type' => 'date',
            'meta' => [
                'interface' => 'datetime',
                'width' => 'quarter',
                'note' => 'null = tillganglig'
            ],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'not_for_loan_status',
            'type' => 'integer',
            'meta' => [
                'interface' => 'input',
                'width' => 'quarter',
                'note' => '0 = kan lanas'
            ],
            'schema' => ['is_nullable' => true, 'default_value' => 0]
        ],
        [
            'field' => 'damaged_status',
            'type' => 'integer',
            'meta' => [
                'interface' => 'input',
                'width' => 'quarter',
                'note' => '0 = ej skadat'
            ],
            'schema' => ['is_nullable' => true, 'default_value' => 0]
        ],
        [
            'field' => 'lost_status',
            'type' => 'integer',
            'meta' => [
                'interface' => 'input',
                'width' => 'quarter',
                'note' => '0 = ej forkommet'
            ],
            'schema' => ['is_nullable' => true, 'default_value' => 0]
        ],
        [
            'field' => 'withdrawn',
            'type' => 'integer',
            'meta' => [
                'interface' => 'input',
                'width' => 'quarter',
                'note' => '0 = ej kasserat'
            ],
            'schema' => ['is_nullable' => true, 'default_value' => 0]
        ],

        // Acquisition and usage
        [
            'field' => 'acquisition_date',
            'type' => 'date',
            'meta' => ['interface' => 'datetime', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'purchase_price',
            'type' => 'float',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'checkouts_count',
            'type' => 'integer',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'default_value' => 0]
        ],
        [
            'field' => 'last_checkout_date',
            'type' => 'timestamp',
            'meta' => ['interface' => 'datetime', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'last_seen_date',
            'type' => 'timestamp',
            'meta' => ['interface' => 'datetime', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true]
        ],

        // Koha timestamp
        [
            'field' => 'koha_timestamp',
            'type' => 'timestamp',
            'meta' => [
                'interface' => 'datetime',
                'readonly' => true,
                'width' => 'quarter'
            ],
            'schema' => ['is_nullable' => true]
        ],

        // Status and sync metadata
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
