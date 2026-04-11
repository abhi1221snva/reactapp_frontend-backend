<?php
/**
 * RVM v2 end-to-end pipeline smoke test.
 *
 *   php tests/rvm_e2e_pipeline_smoke.php
 *
 * Prereqs:
 *   - master DB migrated (`rvm_*` tables exist)
 *   - Redis up
 *   - Local webhook receiver running on 127.0.0.1:9988
 *
 * Flow:
 *   1. Boot Lumen
 *   2. Clean + seed test tenant (wallet + webhook endpoint)
 *   3. Call RvmDropService::createDrop(provider=mock)
 *   4. Invoke ProcessRvmDropJob::handle() synchronously
 *   5. Invoke DeliverWebhookJob::handle() synchronously
 *   6. Verify drop = delivered, wallet committed, event log, delivery row delivered,
 *      local receiver captured request with valid HMAC signature
 *
 * Exits 0 on full pass, 1 on any failure.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->boot();

use App\Jobs\Rvm\DeliverWebhookJob;
use App\Jobs\Rvm\ProcessRvmDropJob;
use App\Model\Master\Rvm\Drop;
use App\Model\Master\Rvm\Event;
use App\Model\Master\Rvm\Wallet;
use App\Model\Master\Rvm\WalletLedger;
use App\Model\Master\Rvm\WebhookDelivery;
use App\Model\Master\Rvm\WebhookEndpoint;
use App\Services\Rvm\DTO\DropRequest;
use App\Services\Rvm\DTO\Priority;
use App\Services\Rvm\RvmComplianceService;
use App\Services\Rvm\RvmDispatchService;
use App\Services\Rvm\RvmDropService;
use App\Services\Rvm\RvmProviderRouter;
use App\Services\Rvm\RvmWalletService;
use App\Services\Rvm\RvmWebhookService;
use App\Services\Rvm\Support\WebhookSigner;
use Illuminate\Support\Facades\DB;

// ── Test config ───────────────────────────────────────────────────────────
const TEST_TENANT_ID = 999_999;
const RECEIVER_URL   = 'http://127.0.0.1:9988/';
const RECEIVER_FILE  = __DIR__ . '/webhook_receiver/latest.json';
const WALLET_SEED    = 1000;   // cents = $10

// Randomize phone per run so the phone rate limiter doesn't block us.
$testPhone = '+1555' . str_pad((string) random_int(1_000_000, 9_999_999), 7, '0', STR_PAD_LEFT);

$pass = 0;
$fail = 0;
$messages = [];
function ok(string $label, bool $cond, int &$pass, int &$fail, array &$m): void
{
    if ($cond) { echo "  [PASS] {$label}\n"; $pass++; }
    else       { echo "  [FAIL] {$label}\n"; $fail++; $m[] = $label; }
}

echo "=== RVM v2 end-to-end pipeline smoke test ===\n";
echo "  tenant_id: " . TEST_TENANT_ID . "\n";
echo "  phone:     {$testPhone}\n";
echo "  receiver:  " . RECEIVER_URL . "\n\n";

// 0. Receiver reachability check
$probe = @file_get_contents(RECEIVER_URL, false, stream_context_create([
    'http' => ['method' => 'GET', 'timeout' => 2, 'ignore_errors' => true],
]));
if ($probe === false) {
    echo "  [ABORT] receiver not reachable. Start it with:\n";
    echo "          php -S 127.0.0.1:9988 -t tests/webhook_receiver &\n";
    exit(2);
}
echo "  receiver reachable: ok\n\n";

// ── 1. Clean slate ────────────────────────────────────────────────────────
echo "[1/6] cleaning prior test data for tenant " . TEST_TENANT_ID . "...\n";
DB::connection('master')->transaction(function () {
    // Grab drop IDs first to wipe child rows
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
    DB::connection('master')->table('rvm_webhook_endpoints')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_wallet_ledger')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_wallet')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_drops')->where('client_id', TEST_TENANT_ID)->delete();
    DB::connection('master')->table('rvm_dnc')->where('client_id', TEST_TENANT_ID)->delete();
});
// Also clear the latest.json receiver file
if (file_exists(RECEIVER_FILE)) @unlink(RECEIVER_FILE);
echo "  done\n\n";

// ── 2. Seed wallet + webhook endpoint ─────────────────────────────────────
echo "[2/6] seeding wallet + webhook endpoint...\n";
$wallet = new Wallet();
$wallet->client_id = TEST_TENANT_ID;
$wallet->balance_cents = WALLET_SEED;
$wallet->reserved_cents = 0;
$wallet->low_balance_threshold_cents = 100;
$wallet->save();

$endpointSecret = WebhookSigner::generateSecret();
$endpoint = new WebhookEndpoint();
$endpoint->client_id = TEST_TENANT_ID;
$endpoint->url = RECEIVER_URL;
$endpoint->secret = $endpointSecret;
$endpoint->events = ['rvm.drop.*'];
$endpoint->active = true;
$endpoint->failure_count = 0;
$endpoint->save();
echo "  wallet={$wallet->balance_cents}c  endpoint_id={$endpoint->id}\n\n";

// ── 3. Create the drop via the service ───────────────────────────────────
echo "[3/6] creating drop via RvmDropService::createDrop()...\n";
/** @var RvmDropService $dropService */
$dropService = app(RvmDropService::class);

$req = new DropRequest(
    phone: $testPhone,
    callerId: '+15555550000',
    voiceTemplateId: 1,
    priority: Priority::NORMAL,
    providerHint: 'mock',
    respectQuietHours: false,   // bypass time window for testing
    metadata: ['test_run' => true, 'source' => 'rvm_e2e_smoke'],
);

try {
    $drop = $dropService->createDrop(
        clientId: TEST_TENANT_ID,
        req: $req,
        idempotencyKey: 'e2e-' . bin2hex(random_bytes(4)),
        userId: 1,
        apiKeyId: null,
    );
} catch (\Throwable $e) {
    echo "  [FAIL] createDrop threw: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
echo "  drop_id={$drop->id}  status={$drop->status}  cost={$drop->cost_cents}c\n";
ok('drop persisted with queued status', $drop->status === 'queued', $pass, $fail, $messages);
ok('drop has reservation_id', !empty($drop->reservation_id), $pass, $fail, $messages);
ok('wallet reserved funds', Wallet::on('master')->where('client_id', TEST_TENANT_ID)->value('reserved_cents') === $drop->cost_cents, $pass, $fail, $messages);
ok('queued event was written', Event::on('master')->where('drop_id', $drop->id)->where('type', 'queued')->exists(), $pass, $fail, $messages);
echo "\n";

// ── 4. Run ProcessRvmDropJob synchronously ────────────────────────────────
echo "[4/6] invoking ProcessRvmDropJob::handle() synchronously...\n";
$processJob = new ProcessRvmDropJob($drop->id);
try {
    $processJob->handle(
        app(RvmProviderRouter::class),
        app(RvmComplianceService::class),
        app(RvmWalletService::class),
        app(RvmWebhookService::class),
        app(RvmDispatchService::class),
    );
} catch (\Throwable $e) {
    echo "  [FAIL] ProcessRvmDropJob threw: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

$drop->refresh();
echo "  status={$drop->status}  provider={$drop->provider}  provider_msg={$drop->provider_message_id}\n";
ok('drop reached delivered status', $drop->status === 'delivered', $pass, $fail, $messages);
ok('provider = mock', $drop->provider === 'mock', $pass, $fail, $messages);
ok('provider_message_id set', !empty($drop->provider_message_id), $pass, $fail, $messages);
ok('delivered_at stamped', $drop->delivered_at !== null, $pass, $fail, $messages);
ok('tries = 1', (int) $drop->tries === 1, $pass, $fail, $messages);

$walletAfter = Wallet::on('master')->where('client_id', TEST_TENANT_ID)->first();
ok('wallet balance debited by cost',
    (int) $walletAfter->balance_cents === WALLET_SEED - (int) $drop->cost_cents,
    $pass, $fail, $messages);
ok('wallet reserved back to 0', (int) $walletAfter->reserved_cents === 0, $pass, $fail, $messages);

$ledgerTypes = WalletLedger::on('master')->where('client_id', TEST_TENANT_ID)->orderBy('id')->pluck('type')->all();
ok('wallet ledger has reserve + commit', $ledgerTypes === ['reserve', 'commit'], $pass, $fail, $messages);

ok('delivered event was written',
    Event::on('master')->where('drop_id', $drop->id)->where('type', 'delivered')->exists(),
    $pass, $fail, $messages);

$delivery = WebhookDelivery::on('master')
    ->where('client_id', TEST_TENANT_ID)
    ->where('drop_id', $drop->id)
    ->orderByDesc('id')
    ->first();
ok('webhook delivery row created', $delivery !== null, $pass, $fail, $messages);
ok('delivery event_type = rvm.drop.delivered',
    $delivery && $delivery->event_type === 'rvm.drop.delivered',
    $pass, $fail, $messages);
ok('delivery status starts as pending',
    $delivery && $delivery->status === 'pending',
    $pass, $fail, $messages);
echo "\n";

// ── 5. Run DeliverWebhookJob synchronously ────────────────────────────────
echo "[5/6] invoking DeliverWebhookJob::handle() synchronously...\n";
$webhookJob = new DeliverWebhookJob((int) $delivery->id);
try {
    $webhookJob->handle(app(WebhookSigner::class));
} catch (\Throwable $e) {
    echo "  [FAIL] DeliverWebhookJob threw: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

$delivery->refresh();
echo "  delivery status={$delivery->status}  code={$delivery->response_code}\n";
ok('delivery status = delivered', $delivery->status === 'delivered', $pass, $fail, $messages);
ok('delivery response_code = 200', (int) $delivery->response_code === 200, $pass, $fail, $messages);
ok('delivery attempt = 1', (int) $delivery->attempt === 1, $pass, $fail, $messages);
ok('delivery delivered_at stamped', $delivery->delivered_at !== null, $pass, $fail, $messages);
echo "\n";

// ── 6. Verify receiver captured the request with a valid signature ────────
echo "[6/6] verifying receiver captured request with valid HMAC...\n";
// Give the receiver a beat to flush the file
usleep(100_000);

if (!file_exists(RECEIVER_FILE)) {
    echo "  [FAIL] receiver latest.json missing — request never arrived\n";
    $fail++;
    $messages[] = 'receiver latest.json missing';
} else {
    $captured = json_decode(file_get_contents(RECEIVER_FILE), true);
    ok('receiver captured a request', is_array($captured), $pass, $fail, $messages);
    ok('receiver method = POST', ($captured['method'] ?? '') === 'POST', $pass, $fail, $messages);

    $sigHeader = $captured['headers']['X-RVM-SIGNATURE']
        ?? $captured['headers']['X-Rvm-Signature']
        ?? '';
    ok('X-Rvm-Signature header present', $sigHeader !== '', $pass, $fail, $messages);

    $eventId = $captured['headers']['X-RVM-EVENT-ID']
        ?? $captured['headers']['X-Rvm-Event-Id']
        ?? '';
    ok('X-Rvm-Event-Id header present', $eventId !== '', $pass, $fail, $messages);

    $eventType = $captured['headers']['X-RVM-EVENT-TYPE']
        ?? $captured['headers']['X-Rvm-Event-Type']
        ?? '';
    ok('X-Rvm-Event-Type = rvm.drop.delivered',
        $eventType === 'rvm.drop.delivered', $pass, $fail, $messages);

    // Verify the signature against our secret
    $signer = app(WebhookSigner::class);
    $rawBody = (string) ($captured['body_raw'] ?? '');
    $sigValid = $signer->verify($rawBody, $endpointSecret, $sigHeader, 600);
    ok('HMAC signature verifies against endpoint secret', $sigValid, $pass, $fail, $messages);

    // Verify payload shape
    $payload = $captured['body_json'] ?? null;
    ok('payload has type = rvm.drop.delivered',
        is_array($payload) && ($payload['type'] ?? '') === 'rvm.drop.delivered',
        $pass, $fail, $messages);
    ok('payload has data.drop.id matching drop',
        is_array($payload) && (($payload['data']['drop']['id'] ?? '') === $drop->id),
        $pass, $fail, $messages);
    ok('payload drop.status = delivered',
        is_array($payload) && (($payload['data']['drop']['status'] ?? '') === 'delivered'),
        $pass, $fail, $messages);
    ok('payload drop.phone matches test phone',
        is_array($payload) && (($payload['data']['drop']['phone'] ?? '') === $testPhone),
        $pass, $fail, $messages);
}

echo "\n=== Results: {$pass} passed, {$fail} failed ===\n";
if ($fail > 0) {
    echo "\nFailed checks:\n";
    foreach ($messages as $m) echo "  - {$m}\n";
}

// Optional cleanup — leave data in place if anything failed, for debugging.
if ($fail === 0) {
    echo "\nCleaning up test data...\n";
    DB::connection('master')->transaction(function () use ($drop) {
        DB::connection('master')->table('rvm_events')->where('drop_id', $drop->id)->delete();
        DB::connection('master')->table('rvm_webhook_deliveries')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_webhook_endpoints')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_wallet_ledger')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_wallet')->where('client_id', TEST_TENANT_ID)->delete();
        DB::connection('master')->table('rvm_drops')->where('client_id', TEST_TENANT_ID)->delete();
    });
    echo "  done\n";
}

exit($fail === 0 ? 0 : 1);
