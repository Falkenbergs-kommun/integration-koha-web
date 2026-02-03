<?php
// Endpoint för att lista alla item types från Koha API
require_once __DIR__ . '/common.php';

// Ladda .env-fil
loadEnv(__DIR__ . '/.env');

// Sätt headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, must-revalidate');

// Hämta konfiguration från .env
$apiBaseUrl = getenv('API_BASE_URL');
$oauthUrl = getenv('OAUTH_URL');
$clientId = getenv('CLIENT_ID');
$clientSecret = getenv('CLIENT_SECRET');

// Hämta OAuth-token
$apiToken = getOAuthToken($oauthUrl, $clientId, $clientSecret);
if (!$apiToken) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kunde inte hämta OAuth-token'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Hämta item types från API
$result = getItemTypesFromApi($apiBaseUrl, $apiToken);

// Kontrollera om hämtningen lyckades
if (!$result['success']) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Kunde inte hämta item types från API',
        'http_code' => $result['http_code'],
        'error' => $result['error']
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Bygg framgångsrik respons
$response = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'count' => count($result['data']),
    'item_types' => $result['data']
];

// Returnera JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
