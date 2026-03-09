<?php
/**
 * CRM Migration 010: Backfill crm_merchant_portals from crm_lead_data
 *
 * Copies unique_token + unique_url from crm_lead_data into crm_merchant_portals.
 * Run AFTER migration 006_create_crm_merchant_portals.sql
 *
 * Usage: php 010_backfill_merchant_portals.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv ?? []);
$host   = '127.0.0.1';
$user   = 'root';
$pass   = 'HG@v2RM8ERULC';

// Discover all client databases
$master = new PDO("mysql:host=$host;dbname=master;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$clientDbs = $master->query(
    "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'client_%' ORDER BY CAST(REPLACE(SCHEMA_NAME,'client_','') AS UNSIGNED)"
)->fetchAll(PDO::FETCH_COLUMN);

$totalInserted = 0;
$totalSkipped  = 0;

foreach ($clientDbs as $dbName) {
    $clientId = (int) str_replace('client_', '', $dbName);

    try {
        $db = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Check table exists
        $exists = $db->query("SHOW TABLES LIKE 'crm_merchant_portals'")->rowCount();
        if (!$exists) {
            echo "[$dbName] SKIP: crm_merchant_portals table does not exist yet\n";
            continue;
        }

        // Fetch leads with unique_token set
        $leads = $db->query("
            SELECT id, unique_token, unique_url, created_at
            FROM crm_lead_data
            WHERE unique_token IS NOT NULL
              AND unique_token != ''
              AND is_deleted = 0
        ")->fetchAll(PDO::FETCH_ASSOC);

        $inserted = 0;
        $skipped  = 0;

        foreach ($leads as $lead) {
            // Skip if already backfilled
            $check = $db->prepare("SELECT id FROM crm_merchant_portals WHERE token = ? LIMIT 1");
            $check->execute([$lead['unique_token']]);
            if ($check->fetchColumn()) {
                $skipped++;
                continue;
            }

            $url = $lead['unique_url'] ?? '';

            if (!$dryRun) {
                $stmt = $db->prepare("
                    INSERT INTO crm_merchant_portals
                        (lead_id, client_id, token, url, status, created_at, updated_at)
                    VALUES
                        (:lead_id, :client_id, :token, :url, 1, :created_at, NOW())
                ");
                $stmt->execute([
                    ':lead_id'    => $lead['id'],
                    ':client_id'  => $clientId,
                    ':token'      => $lead['unique_token'],
                    ':url'        => $url,
                    ':created_at' => $lead['created_at'],
                ]);
            }
            $inserted++;
        }

        $totalInserted += $inserted;
        $totalSkipped  += $skipped;
        echo "[$dbName] Inserted: $inserted, Skipped (already exists): $skipped\n";

    } catch (PDOException $e) {
        echo "[$dbName] ERROR: " . $e->getMessage() . "\n";
    }
}

echo "\n--- Backfill Complete ---\n";
echo "Total Inserted: $totalInserted\n";
echo "Total Skipped:  $totalSkipped\n";
if ($dryRun) {
    echo "(DRY RUN — no changes made)\n";
}
