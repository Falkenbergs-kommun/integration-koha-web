#!/usr/bin/env php
<?php
/**
 * Fix interface options for JSON fields
 * The problem is that options are null, which breaks the interface
 */

require_once __DIR__ . '/common.php';
loadEnv(__DIR__ . '/.env');

$apiUrl = getenv('DIRECTUS_API_URL');
$apiToken = getenv('DIRECTUS_API_TOKEN');

function updateFieldOptions($apiUrl, $apiToken, $field, $interface, $options) {
    $url = "$apiUrl/fields/kft_koha_enriched/$field";

    $data = [
        'meta' => [
            'interface' => $interface,
            'options' => $options
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode >= 200 && $httpCode < 300;
}

echo "===========================================\n";
echo "Fix Interface Options\n";
echo "===========================================\n\n";

$fields = [
    [
        'field' => 'subjects',
        'interface' => 'tags',
        'options' => [
            'iconRight' => 'local_offer'
        ]
    ],
    [
        'field' => 'tags',
        'interface' => 'tags',
        'options' => [
            'iconRight' => 'local_offer'
        ]
    ],
    [
        'field' => 'grounding_search_queries',
        'interface' => 'tags',
        'options' => [
            'iconRight' => 'search'
        ]
    ],
    [
        'field' => 'grounding_sources',
        'interface' => 'input-code',
        'options' => [
            'language' => 'json',
            'lineNumber' => true
        ]
    ]
];

foreach ($fields as $fieldDef) {
    echo "Updating {$fieldDef['field']}...";

    if (updateFieldOptions($apiUrl, $apiToken, $fieldDef['field'], $fieldDef['interface'], $fieldDef['options'])) {
        echo " ✓\n";
    } else {
        echo " ✗\n";
    }
}

echo "\n===========================================\n";
echo "✓ Interface options updated!\n";
echo "===========================================\n\n";

echo "Now refresh Directus GUI and check if tags appear correctly.\n";
echo "If not, the issue is the LONGTEXT datatype (not JSON).\n\n";
