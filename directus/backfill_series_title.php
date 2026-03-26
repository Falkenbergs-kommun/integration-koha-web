#!/usr/bin/env php
<?php
/**
 * Backfill series_title from MARC 490$a
 *
 * Fetches all biblios in Directus where series_title is null,
 * looks up MARC 490$a from Koha API, and updates Directus.
 *
 * Usage: php backfill_series_title.php [--dry-run] [--limit=N] [-v|--verbose]
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';
require_once __DIR__ . '/DirectusClient.php';

function main()
{
    global $argv;
    $verbose = in_array('-v', $argv) || in_array('--verbose', $argv);
    $dryRun = in_array('--dry-run', $argv);
    $limit = null;

    foreach ($argv as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $limit = (int) $m[1];
        }
    }

    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Backfill series_title from MARC 490\$a                    ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    if ($dryRun) echo "DRY RUN — inga ändringar sparas\n\n";

    loadEnv(__DIR__ . '/../.env');

    $config = [
        'OAUTH_URL' => getenv('OAUTH_URL'),
        'CLIENT_ID' => getenv('CLIENT_ID'),
        'CLIENT_SECRET' => getenv('CLIENT_SECRET'),
        'API_BASE_URL' => getenv('API_BASE_URL'),
        'DIRECTUS_API_URL' => getenv('DIRECTUS_API_URL'),
        'DIRECTUS_API_TOKEN' => getenv('DIRECTUS_API_TOKEN')
    ];

    foreach (['OAUTH_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'API_BASE_URL', 'DIRECTUS_API_URL', 'DIRECTUS_API_TOKEN'] as $key) {
        if (empty($config[$key])) {
            throw new Exception("Missing required configuration: {$key}");
        }
    }

    // OAuth token + refresh tracking
    $kohaToken = getOAuthToken($config['OAUTH_URL'], $config['CLIENT_ID'], $config['CLIENT_SECRET']);
    if (!$kohaToken) throw new Exception("Failed to obtain OAuth token");
    $tokenTime = time();
    echo "OAuth token obtained\n\n";

    $directusClient = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], $verbose);
    $directusUrl = $directusClient->getBaseUrl();
    $directusToken = $directusClient->getToken();

    // Fetch biblio_ids where series_title is null
    echo "Fetching biblios without series_title from Directus...\n";
    $perPage = 500;
    $offset = 0;
    $biblios = [];

    while (true) {
        $url = "{$directusUrl}/items/kft_koha_biblios"
             . "?filter[series_title][_null]=true"
             . "&filter[status][_eq]=active"
             . "&fields=id,biblio_id"
             . "&sort=id"
             . "&limit={$perPage}&offset={$offset}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $directusToken,
            'Content-Type: application/json'
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch from Directus (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        $items = $data['data'] ?? [];
        $biblios = array_merge($biblios, $items);

        echo "  Offset {$offset}: " . count($items) . " poster (totalt: " . count($biblios) . ")\n";

        if (count($items) < $perPage) break;
        $offset += $perPage;
    }

    $total = count($biblios);
    echo "Hittade {$total} biblios utan series_title\n\n";

    if ($limit !== null && $limit < $total) {
        $biblios = array_slice($biblios, 0, $limit);
        $total = $limit;
        echo "Begränsat till {$total} poster (--limit)\n\n";
    }

    $stats = ['found' => 0, 'not_found' => 0, 'errors' => 0, 'skipped' => 0];
    $apiBaseUrl = rtrim($config['API_BASE_URL'], '/') . '/';

    echo "Hämtar MARC 490\$a för varje biblio...\n\n";

    foreach ($biblios as $index => $biblio) {
        $biblioId = $biblio['biblio_id'];
        $directusId = $biblio['id'];
        $num = $index + 1;

        // Refresh OAuth token every 45 minutes
        if (time() - $tokenTime > 2700) {
            echo "  Förnyar OAuth-token...\n";
            $kohaToken = getOAuthToken($config['OAUTH_URL'], $config['CLIENT_ID'], $config['CLIENT_SECRET']);
            if (!$kohaToken) throw new Exception("Failed to refresh OAuth token");
            $tokenTime = time();
        }

        $marcUrl = $apiBaseUrl . $biblioId;
        $seriesData = getSeriesFromMarc($marcUrl, $kohaToken);

        if ($seriesData === null) {
            $stats['not_found']++;
            if ($verbose) echo "  [{$num}/{$total}] biblio_id={$biblioId} — ingen 490\n";
            continue;
        }

        $stats['found']++;

        if ($verbose || $num % 100 === 0 || $num === $total) {
            echo "  [{$num}/{$total}] biblio_id={$biblioId} → " . json_encode($seriesData, JSON_UNESCAPED_UNICODE) . "\n";
        }

        if (!$dryRun) {
            try {
                $directusClient->updateItem('kft_koha_biblios', $directusId, [
                    'series_title' => $seriesData
                ]);
            } catch (Exception $e) {
                $stats['errors']++;
                echo "  FEHLER biblio_id={$biblioId}: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n";
    echo "════════════════════════════════════════\n";
    echo "  Resultat:\n";
    echo "  Totalt kontrollerade: {$total}\n";
    echo "  Series title hittad:  {$stats['found']}\n";
    echo "  Ingen 490\$a:         {$stats['not_found']}\n";
    echo "  Fel:                  {$stats['errors']}\n";
    echo "════════════════════════════════════════\n";
}

main();
