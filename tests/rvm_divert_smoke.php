<?php
/**
 * RVM v2 divert smoke test (Phase 5b).
 *
 *   php tests/rvm_divert_smoke.php
 *
 * Prereqs:
 *   - master DB migrated, including the 2026_04_11_200001 divert columns
 *   - Redis up (RvmDropService touches the idempotency store + rate limiter)
 *
 * Exercises RvmDivertService end-to-end, WITHOUT touching SendRvmJob. The
 * divert service is what the v2 legacy-hook calls at dispatch time; this
 * test simulates that call from a clean synthetic state so we can prove:
 *
 *   1. In dry_run mode, divert() persists a new rvm_drops row (provider=mock),
 *      stamps rvm_cdr_log with v2_drop_id/divert_mode/diverted_at, and returns
 *      DivertResult::diverted with the new drop id.
 *   2. A second call on the same cdr row is a no-op with reason=already_diverted
 *      (protects retry + backfill races).
 *   3. A legacy-mode call is a no-op with reason=mode_not_diverted
 *      (refuses to divert tenants that haven't opted in).
 *   4. A payload missing phone/cli fails translate with reason=translate_failed
 *      and leaves rvm_cdr_log untouched.
 *
 * Runs against synthetic tenant 999998 (distinct from the e2e smoke's
 * 999999 so the two tests can live side-by-side). Cleans up on success,
 * leaves data in place on failure for forensics.
 *
 * Exits 0 on full pass, 1 on any failure.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->boot();

use App\Model\Master\Rvm\Drop;
use App\Model\Master\Rvm\TenantFlag;
use App\Model\Master\Rvm\Wallet;
use App\Services\Rvm\RvmDivertService;
use Illuminate\Support\Facades\DB;

// ── Test config ───────────────────────────────────────────────────────────
const TEST_TENANT_ID = 999_998;
const WALLET_SEED    = 1000;   // cents

// Randomize phone so the rate limiter can't block a re-run within the same minute.
$testPhone = '+1555' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
$testCli   = '+15555550000';

$pass = 0;
$fail = 0;
$messages = [];
function ok(string $label, bool $cond, int &$pass, int &$fail, array &$m): void
{
    if ($cond) { echo "  [PASS] {$label}\n"; $pass++; }
    else       { echo "  [FAIL] {$label}\n"; $fail++; $m[] = $label; }
}

echo "=== RVM v2 divert smoke test (Phase 5b) ===\n";
echo "  tenant_id: " . TEST_TENANT_ID . "\n";
echo "  phone:     {$testPhone}\n\n";

// ── 1. Clean slate ────────────────────────────────────────────────────────
echo "[1/6] cleaning prior test data for tenant " . TEST_TENANT_ID . "...\n";
DB::connection('master')->transaction(function () {
    $dropIds = DB::connection('master')
        ->table('rvm_drops')
        ->where('client_id', TEST_TENANT_ID)
        ->pluck('id')
        ->all();

    if ($dropIds) {
        DB::connection('master')->table('rvm_events')->whereIn('drop_id', $dropIds)->delete();
        DB::connection('master')->table('rvm_webhook_deliveries')->whereIn('drop_id', $dropIds)->delete();
    }
    DB::connection('master')->table('rvm_webhook_deliveries')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_wallet_ledger')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_wallet')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_drops')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_dnc')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_tenant_flags')->where('client_id', TEST_TENANT_ID)->delete();
    // Wipe synthetic cdr_log rows left over from previous runs
    DB::connection('master')->table('rvm_cdr_log')
        ->where('api_token', 'divert-smoke-token')
        ->delete();
});
echo "  done\n\n";

// ── 2. Seed wallet + tenant flag (dry_run) ────────────────────────────────
echo "[2/6] seeding wallet + tenant flag = dry_run...\n";
$wallet = new Wallet();
$wallet->client_id = TEST_TENANT_ID;
$wallet->balance_cents = WALLET_SEED;
$wallet->reserved_cents = 0;
$wallet->low_balance_threshold_cents = 100;
$wallet->save();

$flag = new TenantFlag();
$flag->client_id          = TEST_TENANT_ID;
$flag->pipeline_mode      = TenantFlag::MODE_DRY_RUN;
$flag->enabled_by_user_id = 1;
$flag->notes              = 'rvm_divert_smoke synthetic tenant';
$flag->save();
echo "  wallet={$wallet->balance_cents}c  mode={$flag->pipeline_mode}\n\n";

// ── 3. Insert a synthetic legacy cdr row and divert it ────────────────────
echo "[3/6] inserting synthetic rvm_cdr_log row and calling divert()...\n";
$cdrId = DB::connection('master')->table('rvm_cdr_log')->insertGetId([
    'cli'                   => $testCli,
    'phone'                 => $testPhone,
    'api_token'             => 'divert-smoke-token',
    'api_client_name'       => 'Divert Smoke',
    'sip_trunk_name'        => null,
    'sip_trunk_provider'    => null,
    'rvm_domain_id'         => 0,
    'sip_gateway_id'        => 0,
    'voicemail_drop_log_id' => 0,
    'api_type'              => 'rvm',
    'json_data'             => json_encode(['src' => 'smoke']),
    'user_id'               => 1,
    'voicemail_id'          => 42,
    'status'                => null,
    'tries'                 => 0,
    'created_at'            => date('Y-m-d H:i:s'),
    'updated_at'            => date('Y-m-d H:i:s'),
]);
echo "  cdr_id={$cdrId}\n";

$payload = (object) [
    'id'                 => $cdrId,
    'phone'              => $testPhone,
    'cli'                => $testCli,
    'apiToken'           => 'divert-smoke-token',
    'user_id'            => 1,
    'voicemail_id'       => 42,
    'voicemail_file_name'=> 'smoke.wav',
    'rvm_domain_id'      => 0,
    'sip_gateway_id'     => 0,
];

/** @var RvmDivertService $divert */
$divert = app(RvmDivertService::class);

$result = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_DRY_RUN, $payload);
echo "  result: diverted=" . ($result->diverted ? 'true' : 'false')
    . "  v2_drop_id=" . ($result->v2DropId ?? '(null)')
    . "  reason={$result->reason}\n";

ok('divert returned diverted=true', $result->diverted === true, $pass, $fail, $messages);
ok('divert returned a v2 drop id', !empty($result->v2DropId), $pass, $fail, $messages);
ok('divert reason = ok', $result->reason === 'ok', $pass, $fail, $messages);
ok('divert mode = dry_run', $result->mode === TenantFlag::MODE_DRY_RUN, $pass, $fail, $messages);

// ── 3a. Verify rvm_drops row ──────────────────────────────────────────────
$drop = $result->v2DropId
    ? Drop::on('master')->where('id', $result->v2DropId)->first()
    : null;
ok('rvm_drops row persisted for the divert', $drop !== null, $pass, $fail, $messages);
if ($drop) {
    ok('drop.client_id matches test tenant', (int) $drop->client_id === TEST_TENANT_ID, $pass, $fail, $messages);
    ok('drop.phone_e164 matches synthetic phone', $drop->phone_e164 === $testPhone, $pass, $fail, $messages);
    ok('drop.caller_id matches synthetic cli', $drop->caller_id === $testCli, $pass, $fail, $messages);
    ok('drop.provider = mock (dry_run hint)', $drop->provider === 'mock', $pass, $fail, $messages);
    ok('drop.status = queued', $drop->status === 'queued', $pass, $fail, $messages);
    // Drop metadata should stash divert + legacy fields for the audit trail
    $meta = is_string($drop->metadata) ? json_decode($drop->metadata, true) : (array) $drop->metadata;
    ok('drop.metadata.divert.legacy_cdr_id = cdr id',
        is_array($meta) && (int) ($meta['divert']['legacy_cdr_id'] ?? -1) === $cdrId,
        $pass, $fail, $messages);
    ok('drop.metadata.legacy.voicemail_file_name preserved',
        is_array($meta) && ($meta['legacy']['voicemail_file_name'] ?? '') === 'smoke.wav',
        $pass, $fail, $messages);
}

// ── 3b. Verify rvm_cdr_log was stamped ────────────────────────────────────
$cdrRow = DB::connection('master')
    ->table('rvm_cdr_log')
    ->where('id', $cdrId)
    ->first();
ok('rvm_cdr_log.v2_drop_id = divert drop id',
    $cdrRow && $cdrRow->v2_drop_id === $result->v2DropId,
    $pass, $fail, $messages);
ok('rvm_cdr_log.divert_mode = dry_run',
    $cdrRow && $cdrRow->divert_mode === TenantFlag::MODE_DRY_RUN,
    $pass, $fail, $messages);
ok('rvm_cdr_log.diverted_at is not null',
    $cdrRow && $cdrRow->diverted_at !== null,
    $pass, $fail, $messages);

// Also verify the wallet reserved funds on the new drop (proves the v2
// pipeline genuinely executed, not just a bookkeeping stub).
$walletAfter = Wallet::on('master')->where('client_id', TEST_TENANT_ID)->first();
ok('wallet reserved funds for the diverted drop',
    (int) $walletAfter->reserved_cents === (int) $drop->cost_cents,
    $pass, $fail, $messages);
echo "\n";

// ── 4. Idempotency — second divert on same cdr row is a no-op ─────────────
echo "[4/6] calling divert() again on the same cdr row (idempotency check)...\n";
$result2 = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_DRY_RUN, $payload);
echo "  result2: diverted=" . ($result2->diverted ? 'true' : 'false')
    . "  reason={$result2->reason}\n";
ok('second divert returned diverted=false', $result2->diverted === false, $pass, $fail, $messages);
ok('second divert reason = already_diverted',
    $result2->reason === 'already_diverted',
    $pass, $fail, $messages);

// And no second drop row should exist for this tenant.
$dropCount = Drop::on('master')->where('client_id', TEST_TENANT_ID)->count();
ok('no second rvm_drops row was created', $dropCount === 1, $pass, $fail, $messages);
echo "\n";

// ── 5. Mode gating — legacy mode refuses to divert ────────────────────────
echo "[5/6] calling divert() with mode=legacy (should refuse)...\n";
$cdrId2 = DB::connection('master')->table('rvm_cdr_log')->insertGetId([
    'cli'        => $testCli,
    'phone'      => '+15555551111',
    'api_token'  => 'divert-smoke-token',
    'api_type'   => 'rvm',
    'status'     => null,
    'tries'      => 0,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
$payloadLegacy = (object) [
    'id'       => $cdrId2,
    'phone'    => '+15555551111',
    'cli'      => $testCli,
    'apiToken' => 'divert-smoke-token',
];
$resultLegacy = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_LEGACY, $payloadLegacy);
echo "  result: diverted=" . ($resultLegacy->diverted ? 'true' : 'false')
    . "  reason={$resultLegacy->reason}\n";
ok('legacy-mode divert returned diverted=false', $resultLegacy->diverted === false, $pass, $fail, $messages);
ok('legacy-mode divert reason = mode_not_diverted',
    $resultLegacy->reason === 'mode_not_diverted',
    $pass, $fail, $messages);

// cdr row 2 should NOT have been stamped with divert columns
$cdrRow2 = DB::connection('master')->table('rvm_cdr_log')->where('id', $cdrId2)->first();
ok('legacy-mode cdr row NOT marked diverted',
    $cdrRow2 && $cdrRow2->diverted_at === null && $cdrRow2->v2_drop_id === null,
    $pass, $fail, $messages);
echo "\n";

// ── 6. Translate failure — missing phone returns translate_failed ─────────
echo "[6/6] calling divert() with a payload missing phone...\n";
$cdrId3 = DB::connection('master')->table('rvm_cdr_log')->insertGetId([
    'cli'        => $testCli,
    'phone'      => '',
    'api_token'  => 'divert-smoke-token',
    'api_type'   => 'rvm',
    'status'     => null,
    'tries'      => 0,
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
$badPayload = (object) [
    'id'       => $cdrId3,
    'phone'    => null, // missing — should trigger translate_failed
    'cli'      => $testCli,
    'apiToken' => 'divert-smoke-token',
];
$resultBad = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_DRY_RUN, $badPayload);
echo "  result: diverted=" . ($resultBad->diverted ? 'true' : 'false')
    . "  reason={$resultBad->reason}\n";
ok('missing-phone divert returned diverted=false',
    $resultBad->diverted === false,
    $pass, $fail, $messages);
ok('missing-phone divert reason = translate_failed',
    $resultBad->reason === 'translate_failed',
    $pass, $fail, $messages);
$cdrRow3 = DB::connection('master')->table('rvm_cdr_log')->where('id', $cdrId3)->first();
ok('missing-phone cdr row NOT marked diverted',
    $cdrRow3 && $cdrRow3->diverted_at === null,
    $pass, $fail, $messages);
echo "\n";

// ── Results ───────────────────────────────────────────────────────────────
echo "=== Results: {$pass} passed, {$fail} failed ===\n";
if ($fail > 0) {
    echo "\nFailed checks:\n";
    foreach ($messages as $m) echo "  - {$m}\n";
}

// Leave data in place on failure so an operator can poke at rvm_drops /
// rvm_cdr_log directly. Clean up only on success.
if ($fail === 0) {
    echo "\nCleaning up test data...\n";
    DB::connection('master')->transaction(function () {
        $dropIds = DB::connection('master')
            ->table('rvm_drops')
            ->where('client_id', TEST_TENANT_ID)
            ->pluck('id')
            ->all();
        if ($dropIds) {
            DB::connection('master')->table('rvm_events')->whereIn('drop_id', $dropIds)->delete();
            DB::connection('master')->table('rvm_webhook_deliveries')->whereIn('drop_id', $dropIds)->delete();
        }
        DB::connection('master')->table('rvm_wallet_ledger')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_wallet')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_drops')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_tenant_flags')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_cdr_log')
            ->where('api_token', 'divert-smoke-token')
            ->delete();
    });
    echo "  done\n";
}

exit($fail === 0 ? 0 : 1);
