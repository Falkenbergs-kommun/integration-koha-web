#!/usr/bin/env php
<?php
/**
 * Backfill MARC-fält (publication_year, language_code, subjects, genre, SAB, contributors)
 *
 * Hämtar alla aktiva biblios utan publication_year (eller alla med --force),
 * hämtar MARC-post från Koha API, extraherar fält och uppdaterar Directus.
 *
 * Användning: php backfill_marc_fields.php [--dry-run] [--limit=N] [--force] [-v|--verbose]
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
    $force = in_array('--force', $argv);
    $limit = null;

    foreach ($argv as $arg) {
        if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
            $limit = (int) $m[1];
        }
    }

    echo "\n";
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  Backfill MARC-fält (year, lang, subjects, genre, m.m.)    ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    if ($dryRun) echo "DRY RUN — inga ändringar sparas\n\n";
    if ($force) echo "FORCE — uppdaterar alla poster (inte bara saknade)\n\n";

    loadEnv(__DIR__ . '/../.env');

    $config = [
        'OAUTH_URL' => getenv('OAUTH_URL'),
        'CLIENT_ID' => getenv('CLIENT_ID'),
        'CLIENT_SECRET' => getenv('CLIENT_SECRET'),
        'API_BASE_URL' => getenv('API_BASE_URL'),
        'DIRECTUS_API_URL' => getenv('DIRECTUS_API_URL'),
        'DIRECTUS_API_TOKEN' => getenv('DIRECTUS_API_TOKEN')
    ];

    foreach (array_keys($config) as $key) {
        if (empty($config[$key])) {
            throw new Exception("Saknar konfiguration: {$key}");
        }
    }

    // OAuth token + refresh tracking
    $kohaToken = getOAuthToken($config['OAUTH_URL'], $config['CLIENT_ID'], $config['CLIENT_SECRET']);
    if (!$kohaToken) throw new Exception("Kunde inte hämta OAuth-token");
    $tokenTime = time();
    echo "OAuth-token erhållen\n\n";

    $directusClient = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], $verbose);
    $directusUrl = $directusClient->getBaseUrl();
    $directusToken = $directusClient->getToken();

    // Hämta biblios att backfilla
    echo "Hämtar biblios från Directus...\n";
    $perPage = 500;
    $offset = 0;
    $biblios = [];

    while (true) {
        $url = "{$directusUrl}/items/kft_koha_biblios"
             . "?filter[status][_eq]=active"
             . ($force ? '' : '&filter[publication_year][_null]=true')
             . "&fields=id,biblio_id,publication_year"
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
            throw new Exception("Misslyckades hämta från Directus (HTTP {$httpCode}): {$response}");
        }

        $data = json_decode($response, true);
        $items = $data['data'] ?? [];
        $biblios = array_merge($biblios, $items);

        echo "  Offset {$offset}: " . count($items) . " poster (totalt: " . count($biblios) . ")\n";

        if (count($items) < $perPage) break;
        $offset += $perPage;
    }

    $total = count($biblios);
    echo "Hittade {$total} biblios att bearbeta\n\n";

    if ($limit !== null && $limit < $total) {
        $biblios = array_slice($biblios, 0, $limit);
        $total = $limit;
        echo "Begränsat till {$total} poster (--limit)\n\n";
    }

    $stats = [
        'pub_year_found' => 0,
        'language_found' => 0,
        'subjects_found' => 0,
        'genre_found' => 0,
        'sab_found' => 0,
        'contributors_found' => 0,
        'series_found' => 0,
        'marc_failed' => 0,
        'updated' => 0,
        'errors' => 0,
    ];
    $apiBaseUrl = rtrim($config['API_BASE_URL'], '/') . '/';

    echo "Hämtar MARC-data för varje biblio...\n\n";

    foreach ($biblios as $index => $biblio) {
        $biblioId = $biblio['biblio_id'];
        $directusId = $biblio['id'];
        $num = $index + 1;

        // Förnya OAuth-token var 45:e minut
        if (time() - $tokenTime > 2700) {
            echo "  Förnyar OAuth-token...\n";
            $kohaToken = getOAuthToken($config['OAUTH_URL'], $config['CLIENT_ID'], $config['CLIENT_SECRET']);
            if (!$kohaToken) throw new Exception("Kunde inte förnya OAuth-token");
            $tokenTime = time();
        }

        $marcUrl = $apiBaseUrl . $biblioId;
        $marcRecord = fetchMarcRecord($marcUrl, $kohaToken);

        if ($marcRecord === null) {
            $stats['marc_failed']++;
            if ($verbose) echo "  [{$num}/{$total}] biblio_id={$biblioId} — MARC hämtning misslyckades\n";
            continue;
        }

        $marcFields = extractFieldsFromMarc($marcRecord);

        // Bygg uppdatering med alla fält som har data
        $update = [];

        if (!empty($marcFields['publication_year'])) {
            $update['publication_year'] = $marcFields['publication_year'];
            $stats['pub_year_found']++;
        }
        if (!empty($marcFields['language_code'])) {
            $update['language_code'] = $marcFields['language_code'];
            $stats['language_found']++;
        }
        if (!empty($marcFields['subjects'])) {
            $update['subjects_marc'] = $marcFields['subjects'];
            $stats['subjects_found']++;
        }
        if (!empty($marcFields['genre_form'])) {
            $update['genre_form'] = $marcFields['genre_form'];
            $stats['genre_found']++;
        }
        if (!empty($marcFields['sab_classification'])) {
            $update['sab_classification'] = safeTruncate($marcFields['sab_classification'], 50);
            $stats['sab_found']++;
        }
        if (!empty($marcFields['contributors'])) {
            $update['contributors'] = $marcFields['contributors'];
            $stats['contributors_found']++;
        }
        if (!empty($marcFields['series_title'])) {
            $update['series_title'] = $marcFields['series_title'];
            $stats['series_found']++;
        }

        if (empty($update)) {
            if ($verbose) echo "  [{$num}/{$total}] biblio_id={$biblioId} — inga MARC-fält\n";
            continue;
        }

        if ($verbose || $num % 500 === 0 || $num === $total) {
            $fields = implode(', ', array_keys($update));
            echo "  [{$num}/{$total}] biblio_id={$biblioId} → {$fields}\n";
        }

        if (!$dryRun) {
            try {
                $directusClient->updateItem('kft_koha_biblios', $directusId, $update);
                $stats['updated']++;
            } catch (Exception $e) {
                $stats['errors']++;
                echo "  FEL biblio_id={$biblioId}: " . $e->getMessage() . "\n";
            }
        }
    }

    echo "\n";
    echo "════════════════════════════════════════\n";
    echo "  Resultat:\n";
    echo "  Totalt bearbetade:      {$total}\n";
    echo "  Uppdaterade:            {$stats['updated']}\n";
    echo "  ────────────────────────────────────\n";
    echo "  publication_year:       {$stats['pub_year_found']}\n";
    echo "  language_code:          {$stats['language_found']}\n";
    echo "  subjects_marc:          {$stats['subjects_found']}\n";
    echo "  genre_form:             {$stats['genre_found']}\n";
    echo "  sab_classification:     {$stats['sab_found']}\n";
    echo "  contributors:           {$stats['contributors_found']}\n";
    echo "  series_title:           {$stats['series_found']}\n";
    echo "  ────────────────────────────────────\n";
    echo "  MARC misslyckades:      {$stats['marc_failed']}\n";
    echo "  Fel:                    {$stats['errors']}\n";
    echo "════════════════════════════════════════\n";
}

main();
