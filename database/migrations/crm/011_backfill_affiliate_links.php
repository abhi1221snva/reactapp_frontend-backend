<?php
/**
 * CRM Migration 011: Backfill crm_affiliate_links from master.users
 *
 * Reads affiliate_link column from master.users and inserts into
 * crm_affiliate_links in the matching client_N database.
 * Run AFTER migration 005_create_crm_affiliate_links.sql
 *
 * Usage: php 011_backfill_affiliate_links.php [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv ?? []);
$host   = '127.0.0.1';
$user   = 'root';
$pass   = 'HG@v2RM8ERULC';

$master = new PDO("mysql:host=$host;dbname=master;charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Fetch all users with an affiliate_link set
// affiliate_link format: /{client_id}/{extension_id}/{token}
$hasAffiliateLink = false;
try {
    $users = $master->query("
        SELECT id, parent_id, affiliate_link, created_at
        FROM users
        WHERE affiliate_link IS NOT NULL
          AND affiliate_link != ''
          AND parent_id IS NOT NULL
    ")->fetchAll(PDO::FETCH_ASSOC);
    $hasAffiliateLink = true;
} catch (PDOException $e) {
    // Column may not exist on all installations
    echo "master.users.affiliate_link column not found — skipping backfill.\n";
    echo "Error: " . $e->getMessage() . "\n";
    exit(0);
}

$totalInserted = 0;
$totalSkipped  = 0;
$totalErrors   = 0;

foreach ($users as $u) {
    $link     = trim($u['affiliate_link']);
    $clientId = (int) $u['parent_id'];
    $dbName   = "client_$clientId";

    // Parse: /client_id/extension_id/token
    $parts = array_values(array_filter(explode('/', $link)));
    if (count($parts) < 3) {
        echo "[user:{$u['id']}] SKIP: malformed affiliate_link '$link'\n";
        $totalSkipped++;
        continue;
    }

    $extensionId = $parts[count($parts) - 2];
    $token       = $parts[count($parts) - 1];

    try {
        $db = new PDO("mysql:host=$host;dbname=$dbName;charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        // Check table exists
        $exists = $db->query("SHOW TABLES LIKE 'crm_affiliate_links'")->rowCount();
        if (!$exists) {
            echo "[$dbName] SKIP: crm_affiliate_links table does not exist yet\n";
            $totalSkipped++;
            continue;
        }

        // Skip if token already exists
        $check = $db->prepare("SELECT id FROM crm_affiliate_links WHERE token = ? LIMIT 1");
        $check->execute([$token]);
        if ($check->fetchColumn()) {
            $totalSkipped++;
            continue;
        }

        if (!$dryRun) {
            $stmt = $db->prepare("
                INSERT INTO crm_affiliate_links
                    (user_id, client_id, extension_id, token, full_path, label, status, created_at, updated_at)
                VALUES
                    (:user_id, :client_id, :extension_id, :token, :full_path, :label, 1, :created_at, NOW())
            ");
            $stmt->execute([
                ':user_id'      => $u['id'],
                ':client_id'    => $clientId,
                ':extension_id' => $extensionId,
                ':token'        => $token,
                ':full_path'    => $link,
                ':label'        => 'Imported from users.affiliate_link',
                ':created_at'   => $u['created_at'],
            ]);
        }

        echo "[$dbName] user:{$u['id']} → token:$token ✓\n";
        $totalInserted++;

    } catch (PDOException $e) {
        echo "[$dbName] ERROR user:{$u['id']}: " . $e->getMessage() . "\n";
        $totalErrors++;
    }
}

echo "\n--- Backfill Complete ---\n";
echo "Total Inserted: $totalInserted\n";
echo "Total Skipped:  $totalSkipped\n";
echo "Total Errors:   $totalErrors\n";
if ($dryRun) {
    echo "(DRY RUN — no changes made)\n";
}
