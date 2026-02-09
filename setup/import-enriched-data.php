<?php
/**
 * Import enriched book data to Directus collection kft_koha_enriched
 *
 * Usage: php import-enriched-data.php
 *
 * Prerequisites:
 * 1. Create the collection in Directus (use directus-collection-kft_koha_enriched.json or SQL DDL)
 * 2. Set DIRECTUS_API_URL and DIRECTUS_API_TOKEN in .env
 * 3. Run from project root directory
 */

require_once __DIR__ . '/../common.php';

// Load environment variables
loadEnv(__DIR__ . '/../.env');

// Configuration
$directusApiUrl = getenv('DIRECTUS_API_URL') ?: 'https://nav.utvecklingfalkenberg.se';
$directusApiToken = getenv('DIRECTUS_API_TOKEN');
$enrichedDataFile = __DIR__ . '/../enrich/enriched_books.json';

if (!$directusApiToken) {
    die("ERROR: DIRECTUS_API_TOKEN not found in .env file\n");
}

if (!file_exists($enrichedDataFile)) {
    die("ERROR: Enriched data file not found: $enrichedDataFile\n");
}

// Read enriched data
echo "Reading enriched data from: $enrichedDataFile\n";
$enrichedData = json_decode(file_get_contents($enrichedDataFile), true);

if (!$enrichedData) {
    die("ERROR: Failed to parse JSON from enriched data file\n");
}

echo "Found " . count($enrichedData) . " enriched biblios to import\n\n";

// Import each biblio
$successCount = 0;
$errorCount = 0;
$errors = [];

foreach ($enrichedData as $index => $book) {
    $biblioId = $book['biblio_id'];
    echo "Processing biblio_id: $biblioId ($index/" . count($enrichedData) . ")\n";

    // Prepare data for Directus
    $directusData = [
        'biblio_id' => $book['biblio_id'],
        'isbn_clean' => $book['isbn_clean'] ?? null,
        'title' => $book['title'] ?? null,
        'abstract_enriched' => $book['abstract_enriched'] ?? null,
        'subjects' => $book['subjects'] ?? [],
        'tags' => $book['tags'] ?? [],
        'target_audience' => $book['target_audience'] ?? null,
        'grounding_search_queries' => $book['grounding']['search_queries'] ?? [],
        'grounding_sources' => $book['grounding']['sources'] ?? []
    ];

    // Check if biblio already exists (update vs create)
    $checkUrl = "$directusApiUrl/items/kft_koha_enriched?filter[biblio_id][_eq]=$biblioId";
    $checkCh = curl_init($checkUrl);
    curl_setopt($checkCh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($checkCh, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $directusApiToken,
        'Content-Type: application/json'
    ]);
    $checkResponse = curl_exec($checkCh);
    $checkData = json_decode($checkResponse, true);
    curl_close($checkCh);

    $existingId = null;
    if (isset($checkData['data']) && count($checkData['data']) > 0) {
        $existingId = $checkData['data'][0]['id'];
        echo "  Found existing record with id: $existingId - updating\n";
    }

    // Create or update via Directus API
    if ($existingId) {
        // Update existing
        $url = "$directusApiUrl/items/kft_koha_enriched/$existingId";
        $method = 'PATCH';
    } else {
        // Create new
        $url = "$directusApiUrl/items/kft_koha_enriched";
        $method = 'POST';
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($directusData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $directusApiToken,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        echo "  ✓ Success ($method)\n";
        $successCount++;
    } else {
        echo "  ✗ Error ($method) - HTTP $httpCode\n";
        echo "  Response: $response\n";
        $errorCount++;
        $errors[] = [
            'biblio_id' => $biblioId,
            'http_code' => $httpCode,
            'response' => $response
        ];
    }

    echo "\n";

    // Rate limiting - be nice to the API
    usleep(100000); // 100ms delay between requests
}

// Summary
echo "===========================================\n";
echo "Import complete!\n";
echo "  Success: $successCount\n";
echo "  Errors: $errorCount\n";
echo "===========================================\n";

if ($errorCount > 0) {
    echo "\nErrors encountered:\n";
    foreach ($errors as $error) {
        echo "  biblio_id {$error['biblio_id']}: HTTP {$error['http_code']}\n";
    }
    exit(1);
}

exit(0);
