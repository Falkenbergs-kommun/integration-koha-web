#!/usr/bin/env php
<?php
/**
 * Create Directus Collection for Koha Biblios
 *
 * This script creates the 'kft_koha_biblios' collection in Directus
 * with all required fields for storing book data from Koha API.
 *
 * Usage: php create_koha_biblios_collection.php
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

echo "📚 Koha Biblios → Directus Collection Creator\n";
echo "==============================================\n\n";

try {
    $client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
    $collectionName = 'kft_koha_biblios';

    // Check if collection already exists
    if ($client->collectionExists($collectionName)) {
        echo "⚠️  Collection '{$collectionName}' already exists!\n";
        echo "Do you want to delete and recreate it? (y/N): ";
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));

        if (strtolower($line) === 'y') {
            echo "🗑️  Deleting existing collection...\n";
            $client->deleteCollection($collectionName);
            echo "✅ Collection deleted.\n\n";
        } else {
            echo "Aborting...\n";
            exit(0);
        }
    }

    // Create collection
    echo "🔨 Creating collection '{$collectionName}'...\n";
    $client->createCollection($collectionName, []);
    echo "✅ Collection created!\n\n";

    // Get field definitions
    $fields = getFieldDefinitions();

    // Create each field
    echo "📝 Creating " . count($fields) . " fields...\n";
    foreach ($fields as $index => $fieldDef) {
        $fieldName = $fieldDef['field'];
        echo "  [" . ($index + 1) . "/" . count($fields) . "] Creating field '{$fieldName}'...";

        try {
            $client->createField($collectionName, $fieldName, $fieldDef);
            echo " ✅\n";
        } catch (Exception $e) {
            echo " ❌ Error: " . $e->getMessage() . "\n";
        }
    }

    echo "\n✨ Collection setup complete!\n";
    echo "📊 Collection: {$collectionName}\n";
    echo "📋 Fields: " . count($fields) . "\n";
    echo "🔗 View at: {$config['DIRECTUS_API_URL']}/admin/content/{$collectionName}\n";

} catch (Exception $e) {
    echo "❌ Fatal error: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Get field definitions for the kft_koha_biblios collection
 */
function getFieldDefinitions()
{
    return [
        // Primary key - biblio_id from Koha
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
                'is_primary_key' => true,
                'has_auto_increment' => false,
                'is_nullable' => false
            ]
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
            'schema' => [
                'is_nullable' => true
            ]
        ],

        // ISBN fields
        [
            'field' => 'isbn',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'isbn_clean',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],

        // Core bibliographic fields
        [
            'field' => 'title',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'full'],
            'schema' => ['is_nullable' => true, 'max_length' => 500]
        ],
        [
            'field' => 'author',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'abstract',
            'type' => 'text',
            'meta' => ['interface' => 'input-multiline', 'width' => 'full'],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'part_number',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'part_name',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'subtitle',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'full'],
            'schema' => ['is_nullable' => true, 'max_length' => 500]
        ],

        // Publication fields
        [
            'field' => 'publisher',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'publication_year',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'publication_period',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 20]
        ],
        [
            'field' => 'publication_place',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],

        // Physical description
        [
            'field' => 'pages',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 100]
        ],
        [
            'field' => 'material_size',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 100]
        ],

        // Edition and series
        [
            'field' => 'edition_statement',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],
        [
            'field' => 'series_title',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 255]
        ],

        // Additional metadata
        [
            'field' => 'age_restriction',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'ean',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'issn',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'notes',
            'type' => 'text',
            'meta' => ['interface' => 'input-multiline', 'width' => 'full'],
            'schema' => ['is_nullable' => true]
        ],

        // Koha timestamps and metadata
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
        ],

        // URLs
        [
            'field' => 'url',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'full'],
            'schema' => ['is_nullable' => true, 'max_length' => 1000]
        ],
        [
            'field' => 'catalog_link',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'full'],
            'schema' => ['is_nullable' => true, 'max_length' => 500]
        ],

        // Image fields
        [
            'field' => 'image_url',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'full'],
            'schema' => ['is_nullable' => true, 'max_length' => 1000]
        ],
        [
            'field' => 'image_cached',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 500]
        ],
        [
            'field' => 'image_cached_url',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'half'],
            'schema' => ['is_nullable' => true, 'max_length' => 1000]
        ],

        // MARC-derived fields (extracted from MARC-in-JSON, not REST API)
        [
            'field' => 'language_code',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 10]
        ],
        [
            'field' => 'subjects_marc',
            'type' => 'json',
            'meta' => [
                'interface' => 'input-code',
                'options' => ['language' => 'json'],
                'width' => 'full'
            ],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'genre_form',
            'type' => 'json',
            'meta' => [
                'interface' => 'input-code',
                'options' => ['language' => 'json'],
                'width' => 'full'
            ],
            'schema' => ['is_nullable' => true]
        ],
        [
            'field' => 'sab_classification',
            'type' => 'string',
            'meta' => ['interface' => 'input', 'width' => 'quarter'],
            'schema' => ['is_nullable' => true, 'max_length' => 50]
        ],
        [
            'field' => 'contributors',
            'type' => 'json',
            'meta' => [
                'interface' => 'input-code',
                'options' => ['language' => 'json'],
                'width' => 'full'
            ],
            'schema' => ['is_nullable' => true]
        ],

        // Raw data storage
        [
            'field' => 'raw_data',
            'type' => 'json',
            'meta' => [
                'interface' => 'input-code',
                'options' => [
                    'language' => 'json'
                ],
                'width' => 'full'
            ],
            'schema' => [
                'is_nullable' => true
            ]
        ]
    ];
}
?>
