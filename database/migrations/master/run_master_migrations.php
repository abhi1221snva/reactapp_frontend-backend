#!/usr/bin/env php
<?php
/**
 * Master Database Migration Runner
 *
 * Executes SQL migration files against the master database.
 *
 * Usage:
 *   php run_master_migrations.php
 *   php run_master_migrations.php --dry-run
 *
 * Safe to re-run: all SQL uses IF NOT EXISTS.
 */

$dryRun = in_array('--dry-run', $argv);

// Load .env credentials
$envFile = __DIR__ . '/../../../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $val] = explode('=', $line, 2);
        $env[trim($key)] = trim($val, " \t\n\r\0\x0B\"'");
    }
}

$host   = $env['DB_HOST']     ?? '127.0.0.1';
$dbUser = $env['DB_USERNAME'] ?? 'root';
$dbPass = $env['DB_PASSWORD'] ?? '';
$dbName = $env['DB_DATABASE'] ?? 'master';

$migrations = [
    '017_create_onboarding_progress.sql',
    '018_create_email_otps.sql',
    '019_create_phone_otps.sql',
    '020_create_registration_logs.sql',
    '021_create_totp_backup_codes.sql',
    '022_add_totp_columns_to_users.sql',
    '023_add_totp_login_protection_to_users.sql',
    '024_widen_otp_columns.sql',
];

echo "=== Master DB Migration Runner ===\n";
echo "Host: {$host}\nDatabase: {$dbName}\nDry run: " . ($dryRun ? 'YES' : 'NO') . "\n\n";

try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo "ERROR: Cannot connect to master DB: " . $e->getMessage() . "\n";
    exit(1);
}

foreach ($migrations as $file) {
    $path = __DIR__ . '/' . $file;
    if (!file_exists($path)) {
        echo "  [SKIP] {$file} — file not found\n";
        continue;
    }

    $sql = file_get_contents($path);
    echo "  [RUN]  {$file}\n";

    if ($dryRun) {
        echo "  [DRY]  Would execute SQL from {$file}\n";
        continue;
    }

    try {
        $pdo->exec($sql);
        echo "  [OK]   {$file}\n";
    } catch (PDOException $e) {
        echo "  [ERROR] {$file}: " . $e->getMessage() . "\n";
    }
}

echo "\nDone.\n";
