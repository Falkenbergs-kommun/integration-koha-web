#!/usr/bin/env php
<?php
/**
 * Cleanup Duplicate Biblios in Directus
 *
 * Finds biblio_ids with more than one record in kft_koha_biblios,
 * keeps the record with the HIGHEST Directus id (most recently synced),
 * and bulk-deletes all older duplicates.
 *
 * Usage:
 *   php cleanup_duplicates.php           # Normal run (deletes duplicates)
 *   php cleanup_duplicates.php --dry-run # Preview only, no deletions
 *
 * @package    Falkenbergs kommun
 * @subpackage Koha Biblios Sync
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../common.php';

// Parse args
$dryRun = in_array('--dry-run', $argv);

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Directus kft_koha_biblios – Duplicate Cleanup            ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

if ($dryRun) {
    echo "🔍 DRY-RUN mode – inga poster raderas\n\n";
}

$startTime = microtime(true);

// Load config
loadEnv(__DIR__ . '/../.env');
$directusUrl = rtrim(getenv('DIRECTUS_API_URL'), '/');
$token       = getenv('DIRECTUS_API_TOKEN');

if (!$directusUrl || !$token) {
    echo "❌ Saknas DIRECTUS_API_URL eller DIRECTUS_API_TOKEN i .env\n";
    exit(1);
}

// ── Fetch all records (sorted by id ASC, fields id,biblio_id only) ──────────
echo "🔄 Hämtar alla poster från Directus...\n";

$perPage = 500;
$offset  = 0;
$all     = [];

while (true) {
    $url = "{$directusUrl}/items/kft_koha_biblios"
         . "?limit={$perPage}&offset={$offset}&fields=id,biblio_id&sort=id";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json'
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        echo "❌ Misslyckades att hämta offset={$offset} (HTTP {$httpCode}): {$error}\n";
        exit(1);
    }

    $data  = json_decode($response, true);
    $items = $data['data'] ?? [];
    $all   = array_merge($all, $items);

    echo "  Offset {$offset}: " . count($items) . " poster (totalt: " . count($all) . ")\n";

    if (count($items) < $perPage) {
        break;
    }

    $offset += $perPage;
}

echo "✅ Hämtade " . count($all) . " poster totalt\n\n";

// ── Hitta dubbletter ─────────────────────────────────────────────────────────
// Eftersom posterna hämtas i id ASC-ordning, är sista posten per biblio_id
// alltid den med högst id (= senast synkad). Vi sparar den och samlar resten.

echo "🔄 Analyserar dubbletter...\n";

$byBiblioId = [];
foreach ($all as $item) {
    $byBiblioId[$item['biblio_id']][] = $item['id'];
}

$toDelete       = [];  // Directus-IDs att radera
$dupBiblioCount = 0;

foreach ($byBiblioId as $biblioId => $ids) {
    if (count($ids) > 1) {
        $dupBiblioCount++;
        // Behåll den med HÖGST id (sista i listan, sorterat ASC)
        $keep = array_pop($ids);
        foreach ($ids as $oldId) {
            $toDelete[] = $oldId;
        }
    }
}

$totalRecords   = count($all);
$uniqueBiblios  = count($byBiblioId);
$toDeleteCount  = count($toDelete);

echo "✅ Analys klar\n\n";

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  ANALYS                                                    ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📊 Totalt antal poster:        {$totalRecords}\n";
echo "📊 Unika biblio_id:            {$uniqueBiblios}\n";
echo "📊 biblio_id med dubbletter:   {$dupBiblioCount}\n";
echo "🗑️  Poster att radera:          {$toDeleteCount}\n";
echo "\n";

if ($toDeleteCount === 0) {
    echo "✨ Inga dubbletter hittades – inget att städa!\n\n";
    exit(0);
}

if ($dryRun) {
    echo "🔍 DRY-RUN: Skulle ha raderat {$toDeleteCount} poster. Kör utan --dry-run för att radera.\n\n";
    exit(0);
}

// ── Radera i batchar ─────────────────────────────────────────────────────────
echo "🔄 Raderar {$toDeleteCount} dubbletter i batchar om 200...\n";

$batches      = array_chunk($toDelete, 200);
$deletedCount = 0;
$errors       = [];

foreach ($batches as $batchNum => $batch) {
    $ch = curl_init("{$directusUrl}/items/kft_koha_biblios");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['keys' => $batch]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || ($httpCode !== 200 && $httpCode !== 204)) {
        $msg = "Batch " . ($batchNum + 1) . " misslyckades (HTTP {$httpCode}): {$error}";
        $errors[] = $msg;
        echo "  ❌ {$msg}\n";
        continue;
    }

    $deletedCount += count($batch);
    echo "  Batch " . ($batchNum + 1) . "/" . count($batches)
         . " – raderade {$deletedCount}/{$toDeleteCount} poster\n";
}

$duration = round(microtime(true) - $startTime, 1);

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  RESULTAT                                                  ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "✅ Raderade poster:            {$deletedCount}\n";
echo "❌ Fel:                        " . count($errors) . "\n";
echo "⏱️  Tid:                        {$duration}s\n";
echo "\n";

if (count($errors) > 0) {
    echo "Felmeddelanden:\n";
    foreach ($errors as $err) {
        echo "  • {$err}\n";
    }
    echo "\n";
}

echo "✨ Klar! Directus har nu {$uniqueBiblios} unika biblio_id-poster.\n\n";
