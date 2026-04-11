<?php
/**
 * Standalone verifier for RVM v2 schema.
 *   php tests/rvm_verify_tables.php
 *
 * Boots the Lumen app just enough to get the master DB connection, then
 * confirms every expected table + key columns. Non-zero exit on failure.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->boot();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$expected = [
    'rvm_drops'               => ['id', 'client_id', 'phone_e164', 'status', 'priority', 'idempotency_key'],
    'rvm_campaigns'           => ['id', 'client_id', 'status', 'caller_id', 'voice_template_id'],
    'rvm_events'              => ['id', 'drop_id', 'client_id', 'type', 'occurred_at'],
    'rvm_wallet'              => ['client_id', 'balance_cents', 'reserved_cents'],
    'rvm_wallet_ledger'       => ['id', 'client_id', 'type', 'amount_cents', 'balance_after'],
    'rvm_api_keys'            => ['id', 'client_id', 'key_prefix', 'key_hash', 'revoked_at'],
    'rvm_dnc'                 => ['id', 'client_id', 'phone_e164', 'source'],
    'rvm_webhook_endpoints'   => ['id', 'client_id', 'url', 'secret', 'active'],
    'rvm_webhook_deliveries'  => ['id', 'endpoint_id', 'client_id', 'event_type', 'status', 'attempt'],
];

$fail = 0;
$pass = 0;

echo "=== RVM v2 schema verification (master DB) ===\n";
foreach ($expected as $table => $cols) {
    if (!Schema::connection('master')->hasTable($table)) {
        echo "  [FAIL] missing table: {$table}\n";
        $fail++;
        continue;
    }
    $missing = [];
    foreach ($cols as $c) {
        if (!Schema::connection('master')->hasColumn($table, $c)) {
            $missing[] = $c;
        }
    }
    if ($missing) {
        echo "  [FAIL] {$table} missing columns: " . implode(',', $missing) . "\n";
        $fail++;
    } else {
        $rowCount = DB::connection('master')->table($table)->count();
        echo "  [PASS] {$table} (rows: {$rowCount})\n";
        $pass++;
    }
}

// Also verify the unique idempotency index on rvm_drops
$idx = DB::connection('master')->select("SHOW INDEX FROM rvm_drops WHERE Key_name = 'uk_rvm_drops_idem'");
if (count($idx) === 2) { // composite (client_id, idempotency_key) → 2 rows
    echo "  [PASS] uk_rvm_drops_idem composite unique index\n";
    $pass++;
} else {
    echo "  [FAIL] uk_rvm_drops_idem not found or wrong shape\n";
    $fail++;
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
exit($fail === 0 ? 0 : 1);
