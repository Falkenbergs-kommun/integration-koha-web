#!/usr/bin/env php
<?php
/**
 * Setup Directus collection kft_koha_enriched via API
 *
 * This script creates the collection, fields, and relations using Directus REST API
 */

require_once __DIR__ . '/common.php';

// Load environment
loadEnv(__DIR__ . '/.env');

$apiUrl = getenv('DIRECTUS_API_URL');
$apiToken = getenv('DIRECTUS_API_TOKEN');

if (!$apiUrl || !$apiToken) {
    die("ERROR: DIRECTUS_API_URL or DIRECTUS_API_TOKEN not found in .env\n");
}

echo "===========================================\n";
echo "Directus Collection Setup\n";
echo "===========================================\n";
echo "API URL: $apiUrl\n";
echo "Token: " . substr($apiToken, 0, 10) . "...\n\n";

/**
 * Make API request to Directus
 */
function directusApi($method, $endpoint, $data = null, $apiUrl, $apiToken) {
    $url = rtrim($apiUrl, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json'
    ]);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'response' => json_decode($response, true),
        'raw' => $response
    ];
}

// Step 1: Check if collection exists
echo "[1/5] Checking if collection exists...\n";
$result = directusApi('GET', '/collections/kft_koha_enriched', null, $apiUrl, $apiToken);

if ($result['code'] === 200) {
    echo "  ⚠️  Collection already exists. Do you want to continue and update fields? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) !== 'y') {
        die("Aborted by user.\n");
    }
    $collectionExists = true;
} else {
    $collectionExists = false;
    echo "  ✓ Collection does not exist yet\n\n";
}

// Step 2: Create collection
if (!$collectionExists) {
    echo "[2/5] Creating collection...\n";
    $collectionData = [
        'collection' => 'kft_koha_enriched',
        'meta' => [
            'collection' => 'kft_koha_enriched',
            'icon' => 'auto_awesome',
            'note' => 'AI-enriched metadata for library biblios',
            'display_template' => '{{biblio_id}} - {{title}}',
            'hidden' => false,
            'singleton' => false,
            'translations' => null,
            'archive_field' => null,
            'archive_app_filter' => true,
            'archive_value' => null,
            'unarchive_value' => null,
            'sort_field' => 'biblio_id'
        ],
        'schema' => [
            'name' => 'kft_koha_enriched'
        ]
    ];

    $result = directusApi('POST', '/collections', $collectionData, $apiUrl, $apiToken);

    if ($result['code'] >= 200 && $result['code'] < 300) {
        echo "  ✓ Collection created successfully\n\n";
    } else {
        die("  ✗ Failed to create collection: " . $result['raw'] . "\n");
    }
} else {
    echo "[2/5] Skipping collection creation (already exists)\n\n";
}

// Step 3: Create fields
echo "[3/5] Creating fields...\n";

$fields = [
    [
        'field' => 'id',
        'type' => 'integer',
        'meta' => [
            'hidden' => true,
            'interface' => 'input',
            'readonly' => true,
            'special' => ['primary-key']
        ],
        'schema' => [
            'is_primary_key' => true,
            'has_auto_increment' => true,
            'is_nullable' => false
        ]
    ],
    [
        'field' => 'biblio_id',
        'type' => 'integer',
        'meta' => [
            'interface' => 'input',
            'required' => true,
            'note' => 'Foreign key to kft_koha_biblios.biblio_id',
            'width' => 'half'
        ],
        'schema' => [
            'is_nullable' => false,
            'is_unique' => true
        ]
    ],
    [
        'field' => 'isbn_clean',
        'type' => 'string',
        'meta' => [
            'interface' => 'input',
            'width' => 'half',
            'note' => 'Cleaned ISBN without dashes'
        ],
        'schema' => [
            'max_length' => 20
        ]
    ],
    [
        'field' => 'title',
        'type' => 'string',
        'meta' => [
            'interface' => 'input',
            'width' => 'full'
        ],
        'schema' => [
            'max_length' => 500
        ]
    ],
    [
        'field' => 'abstract_enriched',
        'type' => 'text',
        'meta' => [
            'interface' => 'input-rich-text-md',
            'width' => 'full',
            'note' => 'AI-generated enhanced abstract'
        ]
    ],
    [
        'field' => 'subjects',
        'type' => 'json',
        'meta' => [
            'interface' => 'list',
            'width' => 'half',
            'note' => 'Array of subject categories'
        ]
    ],
    [
        'field' => 'tags',
        'type' => 'json',
        'meta' => [
            'interface' => 'tags',
            'width' => 'half',
            'note' => 'Array of searchable tags'
        ]
    ],
    [
        'field' => 'target_audience',
        'type' => 'string',
        'meta' => [
            'interface' => 'select-dropdown',
            'width' => 'half',
            'options' => [
                'choices' => [
                    ['text' => 'Allmänheten', 'value' => 'Allmänheten'],
                    ['text' => 'Gymnasiet', 'value' => 'Gymnasiet'],
                    ['text' => 'Högskola', 'value' => 'Högskola'],
                    ['text' => 'Barn', 'value' => 'Barn']
                ]
            ]
        ],
        'schema' => [
            'max_length' => 255
        ]
    ],
    [
        'field' => 'grounding_search_queries',
        'type' => 'json',
        'meta' => [
            'interface' => 'list',
            'width' => 'half',
            'note' => 'Search queries used by AI'
        ]
    ],
    [
        'field' => 'grounding_sources',
        'type' => 'json',
        'meta' => [
            'interface' => 'list',
            'width' => 'full',
            'note' => 'Sources used by AI with URIs'
        ]
    ],
    [
        'field' => 'date_created',
        'type' => 'timestamp',
        'meta' => [
            'interface' => 'datetime',
            'readonly' => true,
            'special' => ['date-created'],
            'width' => 'half'
        ],
        'schema' => [
            'default_value' => 'CURRENT_TIMESTAMP'
        ]
    ],
    [
        'field' => 'date_updated',
        'type' => 'timestamp',
        'meta' => [
            'interface' => 'datetime',
            'readonly' => true,
            'special' => ['date-updated'],
            'width' => 'half'
        ]
    ]
];

$createdFields = 0;
$skippedFields = 0;

foreach ($fields as $field) {
    echo "  Creating field: {$field['field']}...";

    $result = directusApi('POST', "/fields/kft_koha_enriched", $field, $apiUrl, $apiToken);

    if ($result['code'] >= 200 && $result['code'] < 300) {
        echo " ✓\n";
        $createdFields++;
    } else if ($result['code'] === 400 && strpos($result['raw'], 'already exists') !== false) {
        echo " (already exists)\n";
        $skippedFields++;
    } else {
        echo " ✗ Error: {$result['raw']}\n";
    }
}

echo "\n  Created: $createdFields fields\n";
echo "  Skipped: $skippedFields fields\n\n";

// Step 4: Create relation to kft_koha_biblios
echo "[4/5] Creating relation to kft_koha_biblios...\n";

$relationData = [
    'collection' => 'kft_koha_enriched',
    'field' => 'biblio_id',
    'related_collection' => 'kft_koha_biblios',
    'meta' => [
        'many_collection' => 'kft_koha_enriched',
        'many_field' => 'biblio_id',
        'one_collection' => 'kft_koha_biblios',
        'one_field' => null
    ],
    'schema' => [
        'on_delete' => 'CASCADE'
    ]
];

$result = directusApi('POST', '/relations', $relationData, $apiUrl, $apiToken);

if ($result['code'] >= 200 && $result['code'] < 300) {
    echo "  ✓ Relation created successfully\n\n";
} else if (strpos($result['raw'], 'already exists') !== false) {
    echo "  ⚠️  Relation already exists\n\n";
} else {
    echo "  ⚠️  Note: " . $result['raw'] . "\n\n";
}

// Step 5: Create reverse O2M field on kft_koha_biblios
echo "[5/5] Creating reverse O2M field 'enriched' on kft_koha_biblios...\n";

$o2mField = [
    'field' => 'enriched',
    'type' => 'alias',
    'meta' => [
        'interface' => 'list-o2m',
        'special' => ['o2m'],
        'options' => [
            'template' => '{{abstract_enriched}}'
        ]
    ]
];

$result = directusApi('POST', '/fields/kft_koha_biblios', $o2mField, $apiUrl, $apiToken);

if ($result['code'] >= 200 && $result['code'] < 300) {
    echo "  ✓ O2M field created successfully\n\n";
} else if (strpos($result['raw'], 'already exists') !== false) {
    echo "  ⚠️  O2M field already exists\n\n";
} else {
    echo "  ⚠️  Note: " . $result['raw'] . "\n\n";
}

echo "===========================================\n";
echo "✓ Setup complete!\n";
echo "===========================================\n\n";

echo "Next steps:\n";
echo "1. Import enriched data:\n";
echo "   php docs/import-enriched-data.php\n\n";

echo "2. Test the API:\n";
echo "   curl '$apiUrl/items/kft_koha_enriched' \\\n";
echo "     -H 'Authorization: Bearer $apiToken'\n\n";

echo "3. Test with join:\n";
echo "   curl '$apiUrl/items/kft_koha_biblios/71069?fields=*,enriched.*' \\\n";
echo "     -H 'Authorization: Bearer $apiToken'\n\n";
