<?php
/**
 * CRM Migration 009: Add storage_type column + indexes to crm_label
 * PHP script for safe re-runs
 *
 * Usage: php 009_alter_crm_label_storage_type.php [client_N]
 */

$host  = '127.0.0.1';
$user  = 'root';
$pass  = 'HG@v2RM8ERULC';
$dbArg = $argv[1] ?? null;

$master    = new PDO("mysql:host=$host;dbname=master;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$clientDbs = $dbArg ? [$dbArg] : $master->query(
    "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'client_%' ORDER BY CAST(REPLACE(SCHEMA_NAME,'client_','') AS UNSIGNED)"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($clientDbs as $dbName) {
    try {
        $db = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        echo "[$dbName] CONNECT ERROR: " . $e->getMessage() . "\n";
        continue;
    }

    // Add storage_type column if not exists
    $colExists = $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$dbName' AND TABLE_NAME = 'crm_label' AND COLUMN_NAME = 'storage_type'"
    )->fetchColumn();

    if (!$colExists) {
        try {
            $db->exec("ALTER TABLE `crm_label` ADD COLUMN `storage_type` ENUM('column','eav') NOT NULL DEFAULT 'column' AFTER `heading_type`");
            echo "[$dbName] Added storage_type column\n";
        } catch (PDOException $e) {
            echo "[$dbName] ERROR adding storage_type: " . $e->getMessage() . "\n";
        }
    } else {
        echo "[$dbName] storage_type already exists\n";
    }

    // Add indexes
    foreach (['idx_status' => '`status`', 'idx_column_name' => '`column_name`', 'idx_display_order' => '`display_order`'] as $name => $col) {
        try {
            $db->exec("ALTER TABLE `crm_label` ADD INDEX `$name` ($col)");
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                echo "[$dbName] ERROR index $name: " . $e->getMessage() . "\n";
            }
        }
    }
}

echo "\nDone.\n";
