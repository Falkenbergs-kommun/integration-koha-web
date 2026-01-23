<?php
require_once __DIR__ . '/common.php';

loadEnv(__DIR__ . '/.env');

$config = [
    'OAUTH_URL' => getenv('OAUTH_URL'),
    'CLIENT_ID' => getenv('CLIENT_ID'),
    'CLIENT_SECRET' => getenv('CLIENT_SECRET'),
    'API_BASE_URL' => getenv('API_BASE_URL')
];

echo "🔍 Analyzing Koha API Performance\n";
echo "===================================\n\n";

// Get OAuth token
echo "1. Getting OAuth token...\n";
$startAuth = microtime(true);
$token = getOAuthToken($config['OAUTH_URL'], $config['CLIENT_ID'], $config['CLIENT_SECRET']);
$authTime = microtime(true) - $startAuth;
echo "   ✅ OAuth token obtained in " . number_format($authTime, 2) . "s\n\n";

// Test 1: Count total biblios
echo "2. Counting total biblios in Koha...\n";
$url = rtrim($config['API_BASE_URL'], '/') . '?_per_page=1&_page=1';

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Extract total count from X-Total-Count header
preg_match('/X-Total-Count:\s*(\d+)/i', $response, $matches);
$totalBiblios = isset($matches[1]) ? intval($matches[1]) : 0;

echo "   📚 Total biblios in Koha: " . number_format($totalBiblios) . "\n\n";

// Test 2: Measure API response time for different batch sizes
echo "3. Testing API response times for different batch sizes:\n";
$batchSizes = [10, 50, 100, 250];

foreach ($batchSizes as $batchSize) {
    $url = rtrim($config['API_BASE_URL'], '/') . '?_order_by=-biblio_id&_per_page=' . $batchSize;
    
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $time = microtime(true) - $start;
    $data = json_decode($response, true);
    $actualCount = is_array($data) ? count($data) : 0;
    
    echo "   Batch size {$batchSize}: " . number_format($time, 2) . "s (got {$actualCount} items)\n";
}

echo "\n4. Estimating full sync time:\n";

// Calculate based on observed performance
// From our test: 100 biblios took ~17.91s total (including OAuth, Directus operations)
// Let's estimate more conservatively

$kohaFetchTimePerBatch = 2.0; // seconds per batch from Koha API
$directusTimePerItem = 0.15; // seconds per item for Directus operations
$batchSize = 100;

$totalBatches = ceil($totalBiblios / $batchSize);
$kohaFetchTotal = $totalBatches * $kohaFetchTimePerBatch;
$directusTotal = $totalBiblios * $directusTimePerItem;
$totalEstimated = $authTime + $kohaFetchTotal + $directusTotal;

echo "   Using batch size: {$batchSize}\n";
echo "   Total batches needed: " . number_format($totalBatches) . "\n";
echo "   Estimated Koha API time: " . number_format($kohaFetchTotal / 60, 1) . " minutes\n";
echo "   Estimated Directus time: " . number_format($directusTotal / 60, 1) . " minutes\n";
echo "   Total estimated time: " . number_format($totalEstimated / 60, 1) . " minutes (" . number_format($totalEstimated / 3600, 1) . " hours)\n";

echo "\n5. Recommendations:\n";
echo "   ✓ Use batch size: 100 (good balance)\n";
echo "   ✓ Add delays between batches: 0.5-1.0s\n";
echo "   ✓ Run during low-traffic hours: 02:00-05:00\n";
echo "   ✓ Consider incremental sync after initial full sync\n";

echo "\n✨ Analysis complete!\n";
?>
