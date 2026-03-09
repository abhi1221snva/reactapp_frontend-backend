<?php
/**
 * CRM Migration 001: Add missing indexes to existing CRM tables
 * PHP script handles "Duplicate key name" gracefully — safe to re-run
 *
 * Usage: php 001_add_crm_indexes.php [client_N]
 */

$host  = '127.0.0.1';
$user  = 'root';
$pass  = 'HG@v2RM8ERULC';
$dbArg = $argv[1] ?? null;

// Indexes to create: [table, index_name, columns]
$indexes = [
    ['crm_lead_data',                   'idx_lead_status',           '`lead_status`'],
    ['crm_lead_data',                   'idx_assigned_to',           '`assigned_to`'],
    ['crm_lead_data',                   'idx_is_deleted',            '`is_deleted`'],
    ['crm_lead_data',                   'idx_lead_status_deleted',   '`lead_status`, `is_deleted`'],
    ['crm_lead_data',                   'idx_created_at',            '`created_at`'],
    ['crm_lead_data',                   'idx_updated_at',            '`updated_at`'],
    ['crm_lead_data',                   'idx_lead_source_id',        '`lead_source_id`'],
    ['crm_log',                         'idx_lead_id',               '`lead_id`'],
    ['crm_log',                         'idx_type',                  '`type`'],
    ['crm_log',                         'idx_campaign_id',           '`campaign_id`'],
    ['crm_notifications',               'idx_lead_id',               '`lead_id`'],
    ['crm_notifications',               'idx_lead_type',             '`lead_id`, `type`'],
    ['crm_notifications',               'idx_user_id',               '`user_id`'],
    ['crm_scheduled_task',              'idx_lead_id',               '`lead_id`'],
    ['crm_scheduled_task',              'idx_user_id',               '`user_id`'],
    ['crm_scheduled_task',              'idx_is_sent',               '`is_sent`'],
    ['crm_scheduled_task',              'idx_date',                  '`date`'],
    ['crm_lead_status',                 'idx_title_url',             '`lead_title_url`'],
    ['crm_lead_status',                 'idx_display_order',         '`display_order`'],
    ['crm_lead_status',                 'idx_status',                '`status`'],
    ['crm_label',                       'idx_status',                '`status`'],
    ['crm_label',                       'idx_display_order',         '`display_order`'],
    ['crm_send_lead_to_lender_record',  'idx_lead_id',               '`lead_id`'],
    ['crm_send_lead_to_lender_record',  'idx_lender_id',             '`lender_id`'],
    ['crm_documents',                   'idx_lead_id',               '`lead_id`'],
];

// Discover databases
$master = new PDO("mysql:host=$host;dbname=master;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$clientDbs = $dbArg ? [$dbArg] : $master->query(
    "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'client_%' ORDER BY CAST(REPLACE(SCHEMA_NAME,'client_','') AS UNSIGNED)"
)->fetchAll(PDO::FETCH_COLUMN);

$totalAdded   = 0;
$totalSkipped = 0;
$totalErrors  = 0;

foreach ($clientDbs as $dbName) {
    try {
        $db = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        echo "[$dbName] CONNECT ERROR: " . $e->getMessage() . "\n";
        continue;
    }

    $added = 0; $skipped = 0; $errors = 0;

    foreach ($indexes as [$table, $indexName, $columns]) {
        // Check if table exists in this DB (partial clients may not have all tables)
        $tableExists = $db->query("SHOW TABLES LIKE '$table'")->rowCount();
        if (!$tableExists) {
            continue;
        }

        try {
            $db->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)");
            $added++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                $skipped++; // Already exists — idempotent
            } else {
                echo "[$dbName] ERROR on $table.$indexName: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }

    echo "[$dbName] Added: $added, Already existed: $skipped, Errors: $errors\n";
    $totalAdded   += $added;
    $totalSkipped += $skipped;
    $totalErrors  += $errors;
}

echo "\n--- Index Migration Complete ---\n";
echo "Total Added:    $totalAdded\n";
echo "Total Skipped:  $totalSkipped\n";
echo "Total Errors:   $totalErrors\n";
