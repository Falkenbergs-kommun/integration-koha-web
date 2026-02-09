#!/usr/bin/env php
<?php
/**
 * Fix JSON field types in Directus collection kft_koha_enriched
 * Changes fields from text/longtext to proper JSON type
 */

require_once __DIR__ . '/common.php';
loadEnv(__DIR__ . '/.env');

$apiUrl = getenv('DIRECTUS_API_URL');
$apiToken = getenv('DIRECTUS_API_TOKEN');

if (!$apiUrl || !$apiToken) {
    die("ERROR: Missing DIRECTUS_API_URL or DIRECTUS_API_TOKEN\n");
}

function updateField($apiUrl, $apiToken, $collection, $fieldName) {
    $url = "$apiUrl/fields/$collection/$fieldName";

    $fieldUpdate = [
        'type' => 'json',
        'schema' => [
            'data_type' => 'json'
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fieldUpdate));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'code' => $httpCode,
        'response' => json_decode($response, true)
    ];
}

echo "===========================================\n";
echo "Fixing JSON Field Types\n";
echo "===========================================\n\n";

$fieldsToFix = ['subjects', 'tags', 'grounding_search_queries', 'grounding_sources'];

foreach ($fieldsToFix as $field) {
    echo "Updating field: $field...";
    $result = updateField($apiUrl, $apiToken, 'kft_koha_enriched', $field);

    if ($result['code'] >= 200 && $result['code'] < 300) {
        echo " ✓\n";
    } else {
        echo " ✗\n";
        echo "  Error: " . json_encode($result['response']) . "\n";
    }
}

echo "\n===========================================\n";
echo "Field types updated!\n";
echo "Now run: php docs/import-enriched-data.php\n";
echo "===========================================\n";
