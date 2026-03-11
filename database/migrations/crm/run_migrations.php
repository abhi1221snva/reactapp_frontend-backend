#!/usr/bin/env php
<?php
/**
 * CRM Migration Runner
 *
 * Executes all SQL migration files in numeric order against every client_N database.
 * Then runs PHP backfill scripts (010, 011).
 *
 * Usage:
 *   php run_migrations.php               — run against ALL client databases
 *   php run_migrations.php --db=client_3 — run against a single database only
 *   php run_migrations.php --dry-run     — show what would run without executing
 *
 * Safe to re-run: all SQL uses IF NOT EXISTS / ADD INDEX IF NOT EXISTS
 */

$dryRun   = in_array('--dry-run', $argv);
$singleDb = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--db=') === 0) {
        $singleDb = substr($arg, 5);
    }
}

$host = '127.0.0.1';
$user = 'root';
$pass = 'HG@v2RM8ERULC';
$dir  = __DIR__;

$sqlFiles = [
    // 001 is PHP (uses PDO for Duplicate key error handling)
    '002_create_crm_lead_activity.sql',
    '003_create_crm_lead_status_history.sql',
    '004_create_crm_lead_approvals.sql',
    '005_create_crm_affiliate_links.sql',
    '006_create_crm_merchant_portals.sql',
    '007_create_crm_lead_field_values.sql',
    '008_create_crm_pipeline_views.sql',
    // 009 is PHP (uses PDO for column-exists check and Duplicate key)
    '015_alter_crm_documents_add_columns.sql',
    '016_add_placeholder_to_crm_label.sql',
    '017_upgrade_crm_label.sql',
];

$phpScripts = [
    '001_add_crm_indexes.php',
    '009_alter_crm_label_storage_type.php',
    '010_backfill_merchant_portals.php',
    '011_backfill_affiliate_links.php',
];

echo "=== CRM Migration Runner ===\n";
echo "Host: $host\n";
echo "Dry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

// ─── Discover target databases ────────────────────────────────────────────────
try {
    $master = new PDO("mysql:host=$host;dbname=master;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    die("Cannot connect to master DB: " . $e->getMessage() . "\n");
}

if ($singleDb) {
    $clientDbs = [$singleDb];
} else {
    $clientDbs = $master->query(
        "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA
         WHERE SCHEMA_NAME LIKE 'client_%'
         ORDER BY CAST(REPLACE(SCHEMA_NAME,'client_','') AS UNSIGNED)"
    )->fetchAll(PDO::FETCH_COLUMN);
}

echo "Target databases: " . count($clientDbs) . "\n";
echo implode(', ', $clientDbs) . "\n\n";

// ─── Run SQL files ────────────────────────────────────────────────────────────
$totalOk    = 0;
$totalError = 0;

foreach ($clientDbs as $dbName) {
    echo "--- $dbName ---\n";

    try {
        $db = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    } catch (PDOException $e) {
        echo "  [CONNECT ERROR] " . $e->getMessage() . "\n";
        $totalError++;
        continue;
    }

    foreach ($sqlFiles as $file) {
        $path = "$dir/$file";
        if (!file_exists($path)) {
            echo "  [MISSING] $file\n";
            continue;
        }

        if ($dryRun) {
            echo "  [DRY-RUN] $file\n";
            $totalOk++;
            continue;
        }

        // Use mysql CLI — handles semicolons inside strings correctly
        $cmd    = "mysql -h 127.0.0.1 -u root -p'" . addslashes($pass) . "' " . escapeshellarg($dbName) . " < " . escapeshellarg($path) . " 2>&1";
        $output = shell_exec($cmd);

        // mysql CLI returns empty output on success
        // Ignore known safe warnings: duplicate key, already exists
        $safe = empty($output)
            || strpos($output, 'Duplicate key name') !== false
            || strpos($output, 'already exists') !== false
            || strpos($output, 'Using a password') !== false; // password warning

        if ($safe) {
            echo "  [OK] $file\n";
            $totalOk++;
        } else {
            echo "  [ERROR in $file] $output\n";
            $totalError++;
        }
    }
}

echo "\n=== SQL Migrations Complete ===\n";
echo "OK: $totalOk  |  Errors: $totalError\n\n";

// ─── Run PHP backfill scripts ─────────────────────────────────────────────────
echo "=== Running PHP Backfill Scripts ===\n";
foreach ($phpScripts as $script) {
    $path = "$dir/$script";
    if (!file_exists($path)) {
        echo "[MISSING] $script\n";
        continue;
    }
    echo "\n--- $script ---\n";
    $args = $dryRun ? '--dry-run' : '';
    if ($singleDb) {
        // Backfill scripts don't support --db yet; run anyway (they skip non-existent DBs)
    }
    passthru("php $path $args");
}

echo "\n=== All Migrations Complete ===\n";
