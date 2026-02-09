#!/usr/bin/env php
<?php
/**
 * Create remaining JSON fields via Directus API
 * Tags field already exists and works, creating: subjects, grounding_search_queries, grounding_sources
 */

require_once __DIR__ . '/common.php';
loadEnv(__DIR__ . '/.env');

$apiUrl = getenv('DIRECTUS_API_URL');
$apiToken = getenv('DIRECTUS_API_TOKEN');

if (!$apiUrl || !$apiToken) {
    die("ERROR: Missing DIRECTUS_API_URL or DIRECTUS_API_TOKEN\n");
}

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

echo "===========================================\n";
echo "Create Remaining JSON Fields\n";
echo "===========================================\n\n";

// Fields to create (tags already exists and works)
$fieldsToCreate = [
    [
        'field' => 'subjects',
        'type' => 'json',
        'meta' => [
            'interface' => 'tags',
            'options' => [
                'placeholder' => 'Add subject...',
                'allowCustom' => true,
                'alphabetize' => false
            ],
            'width' => 'half',
            'note' => 'Array of subject categories'
        ],
        'schema' => [
            'data_type' => 'json'
        ]
    ],
    [
        'field' => 'grounding_search_queries',
        'type' => 'json',
        'meta' => [
            'interface' => 'tags',
            'options' => [
                'placeholder' => 'Add query...',
                'allowCustom' => true,
                'alphabetize' => false
            ],
            'width' => 'half',
            'note' => 'Search queries used by AI for grounding'
        ],
        'schema' => [
            'data_type' => 'json'
        ]
    ],
    [
        'field' => 'grounding_sources',
        'type' => 'json',
        'meta' => [
            'interface' => 'input-code',
            'options' => [
                'language' => 'json',
                'lineNumber' => true
            ],
            'width' => 'full',
            'note' => 'Sources used by AI with URIs and titles (JSON array of objects)'
        ],
        'schema' => [
            'data_type' => 'json'
        ]
    ]
];

// Step 1: Delete existing fields if they exist
echo "[1/3] Removing existing fields (if any)...\n";
foreach ($fieldsToCreate as $fieldDef) {
    $fieldName = $fieldDef['field'];
    echo "  Checking field: $fieldName...";

    $result = directusApi('DELETE', "/fields/kft_koha_enriched/$fieldName", null, $apiUrl, $apiToken);

    if ($result['code'] >= 200 && $result['code'] < 300) {
        echo " Deleted\n";
    } else if ($result['code'] === 404) {
        echo " Not found (OK)\n";
    } else {
        echo " Error ({$result['code']})\n";
    }
}

echo "\n[2/3] Creating new fields with correct JSON type...\n";

foreach ($fieldsToCreate as $fieldDef) {
    $fieldName = $fieldDef['field'];
    echo "  Creating field: $fieldName...";

    $result = directusApi('POST', '/fields/kft_koha_enriched', $fieldDef, $apiUrl, $apiToken);

    if ($result['code'] >= 200 && $result['code'] < 300) {
        echo " ✓\n";
    } else {
        echo " ✗ Error ({$result['code']})\n";
        echo "    Response: {$result['raw']}\n";
    }
}

echo "\n[3/3] Clearing existing data and re-importing...\n";

// Get all records
$result = directusApi('GET', '/items/kft_koha_enriched?limit=1000', null, $apiUrl, $apiToken);
$records = $result['response']['data'] ?? [];

echo "  Found " . count($records) . " records\n";

// Delete all records
echo "  Deleting records...";
foreach ($records as $record) {
    $id = $record['id'];
    directusApi('DELETE', "/items/kft_koha_enriched/$id", null, $apiUrl, $apiToken);
}
echo " ✓\n";

echo "\n  Running re-import...\n\n";

// Run import script
passthru('php ' . __DIR__ . '/docs/import-enriched-data.php', $returnCode);

if ($returnCode === 0) {
    echo "\n===========================================\n";
    echo "✓ All fields created and data imported!\n";
    echo "===========================================\n\n";

    echo "Verification:\n";
    echo "1. Check Directus Admin UI → Content → kft_koha_enriched\n";
    echo "2. Open a record and verify:\n";
    echo "   - subjects: Shows as tags/pills\n";
    echo "   - tags: Shows as tags/pills (already working)\n";
    echo "   - grounding_search_queries: Shows as tags/pills\n";
    echo "   - grounding_sources: Shows as JSON code editor\n\n";
} else {
    echo "\n✗ Import failed with code $returnCode\n";
    exit(1);
}
