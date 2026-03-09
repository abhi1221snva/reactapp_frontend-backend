<?php
/**
 * Add updated_by column to crm_lead_data
 * Run against all client_N databases.
 * Usage: php database/migrations/crm/014_add_updated_by_to_lead_data.php [--dry-run]
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

// Find all client_N databases
$databases = $pdo->query("SHOW DATABASES LIKE 'client_%'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($databases as $db) {
    echo "\n=== $db ===\n";

    // Check if crm_lead_data table exists at all
    $tableExists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'crm_lead_data'"
    )->fetchColumn();
    if (!$tableExists) {
        echo "  crm_lead_data table does not exist — skipping.\n";
        continue;
    }

    // Check if column already exists
    $exists = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'crm_lead_data' AND COLUMN_NAME = 'updated_by'"
    )->fetchColumn();

    if ($exists) {
        echo "  updated_by column already exists — skipping.\n";
        continue;
    }

    $sql = "ALTER TABLE `$db`.`crm_lead_data` ADD COLUMN `updated_by` INT UNSIGNED NULL AFTER `created_by`";

    if ($dryRun) {
        echo "  [DRY-RUN] Would run: $sql\n";
    } else {
        $pdo->exec($sql);
        echo "  Added updated_by column.\n";
    }
}

echo "\n[COMPLETE]\n";
