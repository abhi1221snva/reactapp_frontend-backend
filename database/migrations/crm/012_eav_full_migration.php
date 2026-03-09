<?php
/**
 * Phase 3 EAV Full Migration
 * ─────────────────────────
 * 1. Backfill crm_lead_field_values from every option_N column in crm_lead_data.
 * 2. Mark crm_label.storage_type = 'eav' for all option_N rows.
 * 3. Print summary.
 *
 * Run: php database/migrations/crm/012_eav_full_migration.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv ?? []);

// ── credentials from .env ────────────────────────────────────────────────────
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

// ── connect ──────────────────────────────────────────────────────────────────
$pdo = new PDO("mysql:host=$host", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Only migrate client_3 (the active tenant)
$databases = ['client_3'];

foreach ($databases as $db) {
    echo "\n=== Migrating $db ===\n";

    // Get all crm_label rows where column_name starts with 'option_'
    $labels = $pdo->query(
        "SELECT id, column_name, title FROM `$db`.`crm_label`
         WHERE column_name LIKE 'option_%' AND is_deleted = 0"
    )->fetchAll();

    if (empty($labels)) {
        echo "  No option_N labels found. Skipping.\n";
        continue;
    }

    // Get actual option_N columns that exist in crm_lead_data
    $existingCols = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'crm_lead_data'
         AND COLUMN_NAME LIKE 'option_%'"
    )->fetchAll(PDO::FETCH_COLUMN);
    $existingColsSet = array_flip($existingCols);

    // Get all active leads
    $leads = $pdo->query(
        "SELECT id FROM `$db`.`crm_lead_data` WHERE is_deleted = 0"
    )->fetchAll(PDO::FETCH_COLUMN);

    echo "  Labels to migrate: " . count($labels) . "\n";
    echo "  Active leads: " . count($leads) . "\n";

    $inserted = 0;
    $skipped  = 0;

    foreach ($labels as $label) {
        $col      = $label['column_name'];
        $labelId  = $label['id'];

        // Skip if the column doesn't actually exist
        if (!isset($existingColsSet[$col])) {
            echo "  WARN: Column $col not found in $db.crm_lead_data — skipping label #{$labelId}\n";
            continue;
        }

        // Fetch all non-null values for this column
        $rows = $pdo->query(
            "SELECT id AS lead_id, `$col` AS val FROM `$db`.`crm_lead_data`
             WHERE is_deleted = 0 AND `$col` IS NOT NULL AND `$col` != ''"
        )->fetchAll();

        foreach ($rows as $row) {
            // Check if row already exists
            $exists = $pdo->prepare(
                "SELECT COUNT(*) FROM `$db`.`crm_lead_field_values`
                 WHERE lead_id = ? AND label_id = ?"
            );
            $exists->execute([$row['lead_id'], $labelId]);
            if ($exists->fetchColumn() > 0) {
                $skipped++;
                continue;
            }

            if (!$dryRun) {
                $stmt = $pdo->prepare(
                    "INSERT INTO `$db`.`crm_lead_field_values`
                     (lead_id, label_id, column_name, value_text, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())"
                );
                $stmt->execute([$row['lead_id'], $labelId, $col, $row['val']]);
            }
            $inserted++;
        }
    }

    echo "  Rows inserted into crm_lead_field_values: $inserted\n";
    echo "  Rows already existed (skipped): $skipped\n";

    // Mark storage_type = 'eav' for all option_N labels
    if (!$dryRun) {
        $updated = $pdo->exec(
            "UPDATE `$db`.`crm_label`
             SET storage_type = 'eav'
             WHERE column_name LIKE 'option_%'"
        );
        echo "  crm_label rows marked storage_type='eav': $updated\n";
    } else {
        $count = $pdo->query(
            "SELECT COUNT(*) FROM `$db`.`crm_label` WHERE column_name LIKE 'option_%'"
        )->fetchColumn();
        echo "  [DRY-RUN] Would mark $count crm_label rows as eav\n";
    }
}

echo "\n" . ($dryRun ? "[DRY-RUN COMPLETE — no changes made]" : "[MIGRATION COMPLETE]") . "\n";
