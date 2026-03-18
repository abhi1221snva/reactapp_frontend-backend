<?php
/**
 * For each pending migration on a client DB:
 *  - If it would fail because table/column already exists → mark as ran
 *  - Otherwise, let artisan run it normally
 *
 * Usage: php fix_client_migrations.php mysql_60
 *        php fix_client_migrations.php all
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel');

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$target = $argv[1] ?? 'all';

$clientsFile = __DIR__ . '/config/database_clients.php';
$allConnections = file_exists($clientsFile) ? array_keys(require $clientsFile) : [];

$connections = ($target === 'all') ? $allConnections : [$target];

$migrationDir = __DIR__ . '/database/migrations/client';

foreach ($connections as $conn) {
    $clientDb = str_replace('mysql_', 'client_', $conn);
    echo "\n=== $conn ($clientDb) ===\n";

    // Test connection
    try {
        DB::connection($conn)->getPdo();
    } catch (\Exception $e) {
        echo "  Cannot connect: " . $e->getMessage() . "\n";
        continue;
    }

    // Get pending migrations
    $ran = DB::connection($conn)->table('migrations')->pluck('migration')->toArray();
    $files = glob($migrationDir . '/*.php');
    sort($files);

    $pending = [];
    foreach ($files as $f) {
        $name = basename($f, '.php');
        if (!in_array($name, $ran)) $pending[] = ['name' => $name, 'file' => $f];
    }

    if (empty($pending)) { echo "  All up to date.\n"; continue; }

    $marked = 0;
    foreach ($pending as $m) {
        $content = file_get_contents($m['file']);
        $skip = false;

        // Detect Schema::create — if table already exists, skip
        if (preg_match("/Schema::create\(\s*'([^']+)'/", $content, $matches)) {
            $table = $matches[1];
            if (DB::connection($conn)->getSchemaBuilder()->hasTable($table)) {
                _markDone($conn, $m['name']);
                echo "  SKIP (table exists: $table): {$m['name']}\n";
                $marked++;
                $skip = true;
            }
        }

        if ($skip) continue;

        // Detect Schema::table + addColumn — if any column already exists, skip
        if (preg_match("/Schema::table\(\s*'([^']+)'/", $content, $tm)) {
            $table = $tm[1];
            if (DB::connection($conn)->getSchemaBuilder()->hasTable($table)) {
                if (preg_match_all("/->(?:string|integer|bigInteger|unsignedBigInteger|boolean|text|longText|tinyInteger|enum|float|double|decimal|timestamp|date|char|json)\(\s*'([^']+)'/", $content, $cm)) {
                    foreach ($cm[1] as $col) {
                        if (DB::connection($conn)->getSchemaBuilder()->hasColumn($table, $col)) {
                            _markDone($conn, $m['name']);
                            echo "  SKIP (col exists: $col in $table): {$m['name']}\n";
                            $marked++;
                            $skip = true;
                            break;
                        }
                    }
                }
            }
        }
    }

    if ($marked > 0) {
        echo "  Marked $marked as done. Running remaining...\n";
    }

    // Now run remaining pending via artisan
    $output = shell_exec("php artisan migrate --database=$conn --path=database/migrations/client --force 2>&1");
    echo "  " . trim(str_replace("\n", "\n  ", $output)) . "\n";
}

function _markDone(string $conn, string $name): void {
    $batch = DB::connection($conn)->table('migrations')->max('batch') ?? 0;
    DB::connection($conn)->table('migrations')->insertOrIgnore([
        'migration' => $name,
        'batch'     => $batch + 1,
    ]);
}
