#!/usr/bin/env php
<?php
/**
 * Lägg till MARC-baserade fält i kft_koha_biblios
 *
 * Migrationsscript som skapar nya fält i befintlig Directus-kollektion
 * för data som extraheras från MARC-poster (publication_year hanteras redan).
 *
 * Nya fält: language_code, subjects_marc, genre_form, sab_classification, contributors
 *
 * Användning: php add_marc_fields.php [--dry-run]
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/DirectusClient.php';
require_once __DIR__ . '/../common.php';

loadEnv(__DIR__ . '/../.env');

$dryRun = in_array('--dry-run', $argv ?? []);

$config = [
    'DIRECTUS_API_URL' => getenv('DIRECTUS_API_URL'),
    'DIRECTUS_API_TOKEN' => getenv('DIRECTUS_API_TOKEN')
];

if (!$config['DIRECTUS_API_URL'] || !$config['DIRECTUS_API_TOKEN']) {
    die("Saknar DIRECTUS_API_URL eller DIRECTUS_API_TOKEN i .env\n");
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║  Lägg till MARC-fält i kft_koha_biblios                    ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n";
echo "\n";

if ($dryRun) echo "DRY RUN — inga ändringar görs\n\n";

$client = new DirectusClient($config['DIRECTUS_API_URL'], $config['DIRECTUS_API_TOKEN'], true);
$collection = 'kft_koha_biblios';

if (!$client->collectionExists($collection)) {
    die("Kollektion '{$collection}' finns inte. Kör create_koha_biblios_collection.php först.\n");
}

$newFields = [
    [
        'field' => 'language_code',
        'type' => 'string',
        'meta' => ['interface' => 'input', 'width' => 'quarter'],
        'schema' => ['is_nullable' => true, 'max_length' => 10]
    ],
    [
        'field' => 'subjects_marc',
        'type' => 'json',
        'meta' => [
            'interface' => 'input-code',
            'options' => ['language' => 'json'],
            'width' => 'full'
        ],
        'schema' => ['is_nullable' => true]
    ],
    [
        'field' => 'genre_form',
        'type' => 'json',
        'meta' => [
            'interface' => 'input-code',
            'options' => ['language' => 'json'],
            'width' => 'full'
        ],
        'schema' => ['is_nullable' => true]
    ],
    [
        'field' => 'sab_classification',
        'type' => 'string',
        'meta' => ['interface' => 'input', 'width' => 'quarter'],
        'schema' => ['is_nullable' => true, 'max_length' => 50]
    ],
    [
        'field' => 'contributors',
        'type' => 'json',
        'meta' => [
            'interface' => 'input-code',
            'options' => ['language' => 'json'],
            'width' => 'full'
        ],
        'schema' => ['is_nullable' => true]
    ],
];

$created = 0;
$skipped = 0;
$errors = 0;

foreach ($newFields as $fieldDef) {
    $fieldName = $fieldDef['field'];
    echo "  {$fieldName}... ";

    if ($dryRun) {
        echo "SKIPPED (dry-run)\n";
        continue;
    }

    try {
        $client->createField($collection, $fieldName, $fieldDef);
        echo "✅ skapad\n";
        $created++;
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'already exists') !== false || strpos($msg, '400') !== false) {
            echo "⏭️  finns redan\n";
            $skipped++;
        } else {
            echo "❌ {$msg}\n";
            $errors++;
        }
    }
}

echo "\n";
echo "Resultat: {$created} skapade, {$skipped} fanns redan, {$errors} fel\n\n";
