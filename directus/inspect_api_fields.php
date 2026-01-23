#!/usr/bin/env php
<?php
/**
 * Inspect Koha API fields to see what's available
 */

require_once __DIR__ . '/../common.php';

loadEnv(__DIR__ . '/../.env');

echo "Fetching sample biblio from Koha API...\n\n";

// Get OAuth token
$token = getOAuthToken(
    getenv('OAUTH_URL'),
    getenv('CLIENT_ID'),
    getenv('CLIENT_SECRET')
);

if (!$token) {
    die("❌ Failed to get OAuth token\n");
}

// Fetch just 1 biblio to see full structure
$url = rtrim(getenv('API_BASE_URL'), '/') . '?_per_page=1';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    die("❌ API request failed (HTTP {$httpCode})\n");
}

$biblios = json_decode($response, true);

if (empty($biblios[0])) {
    die("❌ No biblios returned\n");
}

$biblio = $biblios[0];

echo "Available fields in Koha API response:\n";
echo "=====================================\n\n";
echo json_encode($biblio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo "\n\n";

echo "Field list:\n";
echo "===========\n";
foreach (array_keys($biblio) as $field) {
    $value = $biblio[$field];
    $type = gettype($value);
    $preview = is_string($value) ? mb_substr($value, 0, 50) : json_encode($value);
    echo "- {$field} ({$type}): {$preview}\n";
}
