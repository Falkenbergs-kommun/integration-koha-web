<?php
require_once __DIR__ . '/common.php';

loadEnv(__DIR__ . '/.env');

echo "🔍 Koha Catalog Analysis\n";
echo "========================\n\n";

// Get OAuth token
echo "Step 1: OAuth Authentication\n";
$startAuth = microtime(true);
$token = getOAuthToken(
    getenv('OAUTH_URL'),
    getenv('CLIENT_ID'),
    getenv('CLIENT_SECRET')
);
$authTime = microtime(true) - $startAuth;
echo "✅ Token obtained in " . number_format($authTime, 3) . "s\n\n";

// Find highest biblio_id to estimate catalog size
echo "Step 2: Finding catalog boundaries\n";
$apiBase = rtrim(getenv('API_BASE_URL'), '/');

// Get newest books (highest biblio_id)
$url = $apiBase . '?_order_by=-biblio_id&_per_page=1';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$highestId = isset($data[0]['biblio_id']) ? $data[0]['biblio_id'] : 0;

echo "Highest biblio_id: " . number_format($highestId) . "\n";

// Get oldest books (lowest biblio_id)
$url = $apiBase . '?_order_by=biblio_id&_per_page=1';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Authorization: Bearer ' . $token
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
$lowestId = isset($data[0]['biblio_id']) ? $data[0]['biblio_id'] : 0;

echo "Lowest biblio_id: " . number_format($lowestId) . "\n";
echo "Estimated range: ~" . number_format($highestId - $lowestId) . " biblios\n\n";

// Test API performance with different batch sizes
echo "Step 3: API Performance Testing\n";
$batchTests = [
    ['size' => 10, 'desc' => 'Small batch'],
    ['size' => 50, 'desc' => 'Medium batch'],
    ['size' => 100, 'desc' => 'Large batch'],
    ['size' => 250, 'desc' => 'XLarge batch']
];

$performanceData = [];

foreach ($batchTests as $test) {
    $url = $apiBase . '?_order_by=-biblio_id&_per_page=' . $test['size'];
    
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
    
    $elapsed = microtime(true) - $start;
    $data = json_decode($response, true);
    $count = is_array($data) ? count($data) : 0;
    
    $performanceData[$test['size']] = [
        'time' => $elapsed,
        'count' => $count,
        'per_item' => $count > 0 ? $elapsed / $count : 0
    ];
    
    echo sprintf("%-12s (size %3d): %5.2fs for %3d items (%4.0fms/item)\n", 
        $test['desc'], $test['size'], $elapsed, $count, 
        ($count > 0 ? ($elapsed / $count) * 1000 : 0)
    );
}

echo "\n";
echo "Step 4: Full Sync Estimation\n";

$estimatedTotal = $highestId; // Conservative estimate

// Use 100 as optimal batch size (good balance)
$optimalBatch = 100;
$kohaTimePerBatch = $performanceData[$optimalBatch]['time'];
$totalBatches = ceil($estimatedTotal / $optimalBatch);

// Calculate Directus time based on our test (100 items in ~18s total, ~0.18s per item)
$directusTimePerItem = 0.18;

// Add delays between batches to be gentle on the system
$delayBetweenBatches = 0.5; // 500ms

// Calculate total time
$kohaFetchTime = $totalBatches * $kohaTimePerBatch;
$directusTime = $estimatedTotal * $directusTimePerItem;
$delayTime = $totalBatches * $delayBetweenBatches;
$totalTime = $authTime + $kohaFetchTime + $directusTime + $delayTime;

echo "Estimated catalog size: ~" . number_format($estimatedTotal) . " biblios\n";
echo "Optimal batch size: {$optimalBatch}\n";
echo "Total batches: " . number_format($totalBatches) . "\n\n";

echo "Time breakdown:\n";
echo "  OAuth:              " . number_format($authTime, 1) . "s\n";
echo "  Koha API fetches:   " . sprintf("%6.1f", $kohaFetchTime / 60) . " min\n";
echo "  Directus sync:      " . sprintf("%6.1f", $directusTime / 60) . " min\n";
echo "  Delays (gentle):    " . sprintf("%6.1f", $delayTime / 60) . " min\n";
echo "  ─────────────────────────────\n";
echo "  TOTAL:              " . sprintf("%6.1f", $totalTime / 60) . " min (" . sprintf("%.1f", $totalTime / 3600) . " hours)\n\n";

echo "Step 5: Load Impact Analysis\n";
$requestsToKoha = $totalBatches;
$avgRequestInterval = $totalTime / $requestsToKoha;
echo "Total requests to Koha: " . number_format($requestsToKoha) . "\n";
echo "Average interval between requests: " . number_format($avgRequestInterval, 1) . "s\n";
echo "Peak load: 1 request every ~" . number_format($kohaTimePerBatch + $delayBetweenBatches, 1) . "s\n\n";

echo "Step 6: Recommendations\n";
echo "✓ Batch size: {$optimalBatch} (optimal balance)\n";
echo "✓ Add 500ms delay between batches (reduces load)\n";
echo "✓ Run during: 02:00-05:00 (low traffic period)\n";
echo "✓ Monitor: First run manually to verify\n";
echo "✓ After initial sync: Use incremental updates\n";

if ($totalTime > 7200) { // More than 2 hours
    echo "⚠️  Initial sync is long - consider splitting across multiple nights\n";
}

echo "\n✨ Analysis complete!\n";
?>
