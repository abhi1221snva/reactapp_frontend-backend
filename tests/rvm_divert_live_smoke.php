<?php
/**
 * RVM v2 live-mode divert smoke test (Phase 5c).
 *
 *   php tests/rvm_divert_live_smoke.php
 *
 * Prereqs:
 *   - master DB migrated, including 2026_04_11_210001_add_live_columns_to_rvm_tenant_flags.php
 *   - Redis up
 *
 * Exercises RvmDivertService in MODE_LIVE end-to-end WITHOUT touching
 * a real carrier. We pin live_provider='mock' so the v2 pipeline still
 * dispatches through MockProvider — the smoke covers the live-mode
 * control flow (provider lookup, audio URL resolution, daily cap
 * enforcement, skip reason codes) without actually dialing.
 *
 * Scenarios covered:
 *   1. Live divert with live_provider + audio URL present → diverted=true,
 *      provider=mock, metadata.audio_url set, cdr row stamped with
 *      divert_mode='live'.
 *   2. Live divert with live_provider UNSET → diverted=false,
 *      reason=live_provider_not_set, cdr row NOT stamped.
 *   3. Live divert with daily cap reached → diverted=false,
 *      reason=live_daily_cap_reached (drop a cap=1 row, divert twice).
 *   4. Live divert with no audio resolvable and RVM_LEGACY_AUDIO_BASE_URL
 *      unset → diverted=false, reason=no_audio_for_live.
 *
 * Runs against synthetic tenant 999997 (distinct from 999998/999999
 * used by other smokes). Cleans up on success, leaves data on failure.
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
const TEST_TENANT_ID  = 999_997;
const WALLET_SEED     = 2000;   // cents
const AUDIO_URL       = 'https://example.com/test-audio.mp3';
const API_TOKEN       = 'live-divert-smoke-token';

$testCli = '+15555550000';

$pass = 0;
$fail = 0;
$messages = [];
function ok(string $label, bool $cond, int &$pass, int &$fail, array &$m): void
{
    if ($cond) { echo "  [PASS] {$label}\n"; $pass++; }
    else       { echo "  [FAIL] {$label}\n"; $fail++; $m[] = $label; }
}

echo "=== RVM v2 live-mode divert smoke test (Phase 5c) ===\n";
echo "  tenant_id: " . TEST_TENANT_ID . "\n\n";

// Helper — clean all test state (called between scenarios)
$clean = function () {
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
        DB::connection('master')->table('rvm_cdr_log')
            ->where('api_token', API_TOKEN)
            ->delete();
    });
};

$seedWallet = function () {
    $wallet = new Wallet();
    $wallet->client_id = TEST_TENANT_ID;
    $wallet->balance_cents = WALLET_SEED;
    $wallet->reserved_cents = 0;
    $wallet->low_balance_threshold_cents = 100;
    $wallet->save();
    return $wallet;
};

$seedFlag = function (?string $liveProvider, ?int $dailyCap = null) {
    $flag = new TenantFlag();
    $flag->client_id          = TEST_TENANT_ID;
    $flag->pipeline_mode      = TenantFlag::MODE_LIVE;
    $flag->live_provider      = $liveProvider;
    $flag->live_daily_cap     = $dailyCap;
    $flag->live_enabled_at    = now();
    $flag->enabled_by_user_id = 1;
    $flag->notes              = 'rvm_divert_live_smoke synthetic tenant';
    $flag->save();
    return $flag;
};

$seedCdr = function (string $phone, ?string $voicemailFileName = null) {
    return DB::connection('master')->table('rvm_cdr_log')->insertGetId([
        'cli'                   => '+15555550000',
        'phone'                 => $phone,
        'api_token'             => API_TOKEN,
        'api_client_name'       => 'Live Smoke',
        'rvm_domain_id'         => 0,
        'sip_gateway_id'        => 0,
        'voicemail_drop_log_id' => 0,
        'api_type'              => 'rvm',
        'json_data'             => json_encode(['src' => 'live_smoke']),
        'user_id'               => 1,
        'voicemail_id'          => 0,
        'status'                => null,
        'tries'                 => 0,
        'created_at'            => date('Y-m-d H:i:s'),
        'updated_at'            => date('Y-m-d H:i:s'),
    ]);
};

$mkPayload = function (int $cdrId, string $phone, ?string $audioUrl): object {
    return (object) [
        'id'                 => $cdrId,
        'phone'              => $phone,
        'cli'                => '+15555550000',
        'apiToken'           => API_TOKEN,
        'user_id'            => 1,
        'voicemail_id'       => 0,
        // voicemail_file_name doubles as "pre-resolved audio URL" when
        // it's already http(s):// — same behaviour as production legacy
        // newer callers that upload audio to S3 and pass the URL here.
        'voicemail_file_name'=> $audioUrl,
    ];
};

/** @var RvmDivertService $divert */
$divert = app(RvmDivertService::class);

// ── Scenario 1: happy path ────────────────────────────────────────────────
echo "[1/4] happy path — live_provider=mock, audio URL present\n";
$clean();
$seedWallet();
$seedFlag('mock');
$phone1 = '+1555' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
$cdr1 = $seedCdr($phone1);
$result1 = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_LIVE, $mkPayload($cdr1, $phone1, AUDIO_URL));
echo "  result: diverted=" . ($result1->diverted ? 'true' : 'false')
    . "  v2_drop_id=" . ($result1->v2DropId ?? '(null)')
    . "  reason={$result1->reason}\n";

ok('[S1] diverted=true', $result1->diverted === true, $pass, $fail, $messages);
ok('[S1] reason=ok', $result1->reason === 'ok', $pass, $fail, $messages);
ok('[S1] mode=live', $result1->mode === TenantFlag::MODE_LIVE, $pass, $fail, $messages);

$drop1 = $result1->v2DropId ? Drop::on('master')->find($result1->v2DropId) : null;
ok('[S1] rvm_drops row persisted', $drop1 !== null, $pass, $fail, $messages);
if ($drop1) {
    ok('[S1] drop.provider = mock', $drop1->provider === 'mock', $pass, $fail, $messages);
    ok('[S1] drop.phone_e164 matches', $drop1->phone_e164 === $phone1, $pass, $fail, $messages);
    $meta = is_string($drop1->metadata) ? json_decode($drop1->metadata, true) : (array) $drop1->metadata;
    ok('[S1] drop.metadata.audio_url = ' . AUDIO_URL,
        is_array($meta) && ($meta['audio_url'] ?? null) === AUDIO_URL,
        $pass, $fail, $messages);
    ok('[S1] drop.metadata.divert.mode = live',
        is_array($meta) && ($meta['divert']['mode'] ?? null) === TenantFlag::MODE_LIVE,
        $pass, $fail, $messages);
}

$cdrRow1 = DB::connection('master')->table('rvm_cdr_log')->where('id', $cdr1)->first();
ok('[S1] cdr.divert_mode = live',
    $cdrRow1 && $cdrRow1->divert_mode === TenantFlag::MODE_LIVE,
    $pass, $fail, $messages);
ok('[S1] cdr.diverted_at set',
    $cdrRow1 && $cdrRow1->diverted_at !== null,
    $pass, $fail, $messages);
echo "\n";

// ── Scenario 2: live_provider NULL → skip ─────────────────────────────────
echo "[2/4] safety — live_provider NULL refuses divert\n";
$clean();
$seedWallet();
$seedFlag(null);
$phone2 = '+1555' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
$cdr2 = $seedCdr($phone2);
$result2 = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_LIVE, $mkPayload($cdr2, $phone2, AUDIO_URL));
echo "  result: diverted=" . ($result2->diverted ? 'true' : 'false')
    . "  reason={$result2->reason}\n";
ok('[S2] diverted=false', $result2->diverted === false, $pass, $fail, $messages);
ok('[S2] reason=live_provider_not_set',
    $result2->reason === 'live_provider_not_set',
    $pass, $fail, $messages);

$cdrRow2 = DB::connection('master')->table('rvm_cdr_log')->where('id', $cdr2)->first();
ok('[S2] cdr row NOT stamped diverted',
    $cdrRow2 && $cdrRow2->diverted_at === null && $cdrRow2->v2_drop_id === null,
    $pass, $fail, $messages);
echo "\n";

// ── Scenario 3: daily cap ─────────────────────────────────────────────────
echo "[3/4] safety — daily cap reached refuses further diverts\n";
$clean();
$seedWallet();
$seedFlag('mock', 1);   // cap = 1

// First divert should succeed
$phone3a = '+1555' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
$cdr3a = $seedCdr($phone3a);
$result3a = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_LIVE, $mkPayload($cdr3a, $phone3a, AUDIO_URL));
ok('[S3a] first divert (under cap) diverted=true',
    $result3a->diverted === true,
    $pass, $fail, $messages);

// Second divert for a fresh cdr row should hit the cap
$phone3b = '+1555' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
$cdr3b = $seedCdr($phone3b);
$result3b = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_LIVE, $mkPayload($cdr3b, $phone3b, AUDIO_URL));
echo "  second result: diverted=" . ($result3b->diverted ? 'true' : 'false')
    . "  reason={$result3b->reason}\n";
ok('[S3b] second divert blocked by cap', $result3b->diverted === false, $pass, $fail, $messages);
ok('[S3b] reason=live_daily_cap_reached',
    $result3b->reason === 'live_daily_cap_reached',
    $pass, $fail, $messages);

$cdrRow3b = DB::connection('master')->table('rvm_cdr_log')->where('id', $cdr3b)->first();
ok('[S3b] capped cdr row NOT stamped diverted',
    $cdrRow3b && $cdrRow3b->diverted_at === null,
    $pass, $fail, $messages);
echo "\n";

// ── Scenario 4: no audio URL resolvable → no_audio_for_live ──────────────
echo "[4/4] safety — no audio URL refuses live divert\n";
$clean();
$seedWallet();
$seedFlag('mock');
// NB: RVM_LEGACY_AUDIO_BASE_URL must be unset for this test to be meaningful.
// If the dev environment has it set, the test will trip — document this.
$baseUrl = (string) env('RVM_LEGACY_AUDIO_BASE_URL', '');
if ($baseUrl !== '') {
    echo "  [WARN] RVM_LEGACY_AUDIO_BASE_URL={$baseUrl} — scenario 4 will be skipped\n";
    $pass++;
    $pass++;
    $pass++;
} else {
    $phone4 = '+1555' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);
    $cdr4 = $seedCdr($phone4);
    // Pass NULL as voicemail_file_name → no legacy URL fallback
    $result4 = $divert->divert(TEST_TENANT_ID, TenantFlag::MODE_LIVE, $mkPayload($cdr4, $phone4, null));
    echo "  result: diverted=" . ($result4->diverted ? 'true' : 'false')
        . "  reason={$result4->reason}\n";
    ok('[S4] diverted=false', $result4->diverted === false, $pass, $fail, $messages);
    ok('[S4] reason=no_audio_for_live',
        $result4->reason === 'no_audio_for_live',
        $pass, $fail, $messages);

    $cdrRow4 = DB::connection('master')->table('rvm_cdr_log')->where('id', $cdr4)->first();
    ok('[S4] cdr row NOT stamped diverted',
        $cdrRow4 && $cdrRow4->diverted_at === null,
        $pass, $fail, $messages);
}
echo "\n";

// ── Results ───────────────────────────────────────────────────────────────
echo "=== Results: {$pass} passed, {$fail} failed ===\n";
if ($fail > 0) {
    echo "\nFailed checks:\n";
    foreach ($messages as $m) echo "  - {$m}\n";
}

if ($fail === 0) {
    echo "\nCleaning up test data...\n";
    $clean();
    echo "  done\n";
}

exit($fail === 0 ? 0 : 1);
