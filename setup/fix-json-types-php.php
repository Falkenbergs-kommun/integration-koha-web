#!/usr/bin/env php
<?php
/**
 * Fix JSON field types by connecting directly to MySQL
 * This bypasses Directus API limitations
 */

require_once __DIR__ . '/common.php';
loadEnv(__DIR__ . '/.env');

echo "===========================================\n";
echo "Fix JSON Column Types via MySQL\n";
echo "===========================================\n\n";

// Database connection details
echo "Enter MySQL connection details:\n";
echo "Host [localhost]: ";
$host = trim(fgets(STDIN));
if (empty($host)) $host = 'localhost';

echo "Database [directus]: ";
$database = trim(fgets(STDIN));
if (empty($database)) $database = 'directus';

echo "Username: ";
$username = trim(fgets(STDIN));

echo "Password: ";
// Hide password input
system('stty -echo');
$password = trim(fgets(STDIN));
system('stty echo');
echo "\n\n";

// Connect to MySQL
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "✓ Connected to MySQL\n\n";
} catch (PDOException $e) {
    die("✗ Connection failed: " . $e->getMessage() . "\n");
}

// Step 1: Clear existing data (stringified JSON cannot be converted)
echo "[1/3] Clearing existing data...\n";
try {
    $pdo->exec("UPDATE kft_koha_enriched SET
        subjects = NULL,
        tags = NULL,
        grounding_search_queries = NULL,
        grounding_sources = NULL");
    echo "  ✓ Data cleared\n\n";
} catch (PDOException $e) {
    die("  ✗ Error: " . $e->getMessage() . "\n");
}

// Step 2: Change column types to JSON
echo "[2/3] Changing column types to JSON...\n";
try {
    $pdo->exec("ALTER TABLE kft_koha_enriched
        MODIFY COLUMN subjects JSON,
        MODIFY COLUMN tags JSON,
        MODIFY COLUMN grounding_search_queries JSON,
        MODIFY COLUMN grounding_sources JSON");
    echo "  ✓ Column types changed to JSON\n\n";
} catch (PDOException $e) {
    die("  ✗ Error: " . $e->getMessage() . "\n");
}

// Step 3: Verify
echo "[3/3] Verifying changes...\n";
try {
    $stmt = $pdo->query("DESCRIBE kft_koha_enriched");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $jsonFields = ['subjects', 'tags', 'grounding_search_queries', 'grounding_sources'];
    foreach ($columns as $col) {
        if (in_array($col['Field'], $jsonFields)) {
            $type = $col['Type'];
            $status = ($type === 'json') ? '✓' : '✗';
            echo "  $status {$col['Field']}: $type\n";
        }
    }
    echo "\n";
} catch (PDOException $e) {
    die("  ✗ Error: " . $e->getMessage() . "\n");
}

echo "===========================================\n";
echo "✓ Column types updated to JSON!\n";
echo "===========================================\n\n";

echo "Next step: Re-import data\n";
echo "$ php docs/import-enriched-data.php\n\n";

// Offer to run import automatically
echo "Run import now? (y/n): ";
$answer = trim(fgets(STDIN));

if (strtolower($answer) === 'y') {
    echo "\nRunning import...\n\n";
    passthru('php ' . __DIR__ . '/docs/import-enriched-data.php', $returnCode);

    if ($returnCode === 0) {
        echo "\n===========================================\n";
        echo "✓ All done! Check Directus GUI now.\n";
        echo "===========================================\n";
    }
}
