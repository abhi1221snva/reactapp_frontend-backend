<?php
/**
 * Phase 3 — Drop option_N columns from crm_lead_data
 * ─────────────────────────────────────────────────────
 * Drops all columns whose crm_label.storage_type = 'eav' and which
 * currently exist as physical columns in crm_lead_data.
 *
 * Run AFTER 012_eav_full_migration.php and controller changes are deployed.
 *
 * Usage: php database/migrations/crm/013_drop_option_columns.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv ?? []);

$envFile = __DIR__ . '/../../../.env';
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim($v);
}
$host = $env['DB_HOST']     ?? '127.0.0.1';
$user = $env['DB_USERNAME'] ?? 'root';
$pass = $env['DB_PASSWORD'] ?? '';

$pdo = new PDO("mysql:host=$host", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$db = 'client_3';
echo "\n=== Dropping option_N columns from $db.crm_lead_data ===\n";

// Get all crm_label column_names where storage_type = 'eav'
$eavCols = $pdo->query(
    "SELECT column_name FROM `$db`.`crm_label`
     WHERE storage_type = 'eav' AND column_name LIKE 'option_%'"
)->fetchAll(PDO::FETCH_COLUMN);

// Get columns that actually exist in crm_lead_data
$existingCols = $pdo->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'crm_lead_data'
     AND COLUMN_NAME LIKE 'option_%'"
)->fetchAll(PDO::FETCH_COLUMN);

$toDrop = array_intersect($eavCols, $existingCols);

if (empty($toDrop)) {
    echo "  No columns to drop (already dropped or none match).\n";
    exit(0);
}

echo "  Columns to drop: " . count($toDrop) . "\n";
echo "  " . implode(', ', $toDrop) . "\n";

if ($dryRun) {
    echo "\n[DRY-RUN — no changes made]\n";
    exit(0);
}

// Build ALTER TABLE DROP COLUMN statement (batch for efficiency)
$drops = array_map(fn($c) => "DROP COLUMN `$c`", $toDrop);
$sql   = "ALTER TABLE `$db`.`crm_lead_data` " . implode(', ', $drops);

$pdo->exec($sql);
echo "\n  Done. Dropped " . count($toDrop) . " columns.\n";
echo "[COMPLETE]\n";
