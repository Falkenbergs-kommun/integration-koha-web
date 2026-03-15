#!/usr/bin/env php
<?php
/**
 * Create Directus Collection for Koha Branches (Libraries)
 *
 * This script creates the 'kft_koha_branches' collection in Directus
 * with all required fields for storing library/branch data from Koha API.
 *
 * Usage: php create_koha_branches_collection.php
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Branches Sync
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

echo "Koha Branches -> Directus Collection Creator\n";
echo "==============================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_branches';

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

    // Use raw API call to set custom meta (icon, display_template)
    $url = rtrim($config['DIRECTUS_API_URL'], '/') . '/collections';
    $collectionData = [
        'collection' => $collectionName,
        'meta' => [
            'collection' => $collectionName,
            'icon' => 'account_balance',
            'note' => 'Koha biblioteksfilialer - Synkroniserad fran Koha API',
            'display_template' => '{{library_id}} – {{name}}',
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
 * Get field definitions for the kft_koha_branches collection
 */
function getFieldDefinitions()
{
    return [
        // Koha library_id (unique identifier, not PK - Directus auto-creates id as PK)
        [
            'field' => 'library_id',
            'type' => 'string',
            'meta' => [
                'interface' => 'input',
                'readonly' => true,
                'hidden' => false,
                'width' => 'half'
            ],
            'schema' => [
                'is_nullable' => false,
                'is_unique' => true,
                'max_length' => 20
            ]
        ],

        // Display name
        [
            'field' => 'name',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],

        // Address fields
        [
            'field' => 'address1',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'full'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'address2',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'address3',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'city',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 100]
        ],
        [
            'field' => 'postal_code',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 20]
        ],
        [
            'field' => 'country',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 100]
        ],

        // Contact info
        [
            'field' => 'phone',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'email',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'url',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'full'],
            'schema' => ['is_nullable' => true, 'max_length' => 500]
        ],

        // Flags
        [
            'field' => 'pickup_location',
            'type' => 'boolean',
            'meta' => ['interface' => 'boolean', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'default_value' => false]
        ],
        [
            'field' => 'is_public',
            'type' => 'boolean',
            'meta' => ['interface' => 'boolean', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'default_value' => true]
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
