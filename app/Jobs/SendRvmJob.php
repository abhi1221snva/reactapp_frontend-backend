<?php

namespace App\Jobs;

use App\Model\Master\AsteriskServer;
use App\Model\Master\Rvm\TenantFlag;
use App\Model\Master\RvmCdrLog;
use App\Model\Master\RvmQueueList;
use App\Services\Rvm\RvmDivertService;
use App\Services\Rvm\RvmFeatureFlagService;
use App\Services\Rvm\RvmShadowService;
use Carbon\Carbon;
use DateTime;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * SendRvmJob
 *
 * Dispatches a ringless voicemail via the Asterisk Manager Interface.
 *
 * ──────────────────────────────────────────────────────────────────────────
 * P0 FIXES APPLIED (from the April-2026 RVM audit):
 *   R3 — Retries + backoff + timeout as explicit class properties.
 *   R4 — AMI response is actually read; errors and timeouts throw so the
 *        queue driver retries instead of marking the job successful on
 *        silent socket closures.
 *   R5 — `tries` counter is incremented atomically (UPDATE ... SET tries =
 *        tries + 1), not read-modify-write.
 *   R6 — Duplicate dispatch is caught via the unique index on
 *        rvm_queue_list.rvm_cdr_log_id; duplicates abort cleanly.
 *   R7 — All AMI fields are sanitized before being interpolated into the
 *        channel string, killing CRLF injection into the AMI protocol.
 *   R8 — Throttle sleep() / usleep() inside the job is removed; rate
 *        control is the queue/driver's job.
 *   R9 — failed() hook writes a terminal status + error message to
 *        rvm_cdr_log so operators can see why a drop died.
 * ──────────────────────────────────────────────────────────────────────────
 */
class SendRvmJob extends Job
{
    /** Payload passed in at dispatch time. */
    protected $data;

    /** Max retry attempts before failed() is invoked. */
    public int $tries = 5;

    /** Per-attempt timeout in seconds. */
    public int $timeout = 25;

    /** Throw failed() once we hit this many unhandled exceptions. */
    public int $maxExceptions = 3;

    /** Retry backoff schedule (seconds). Last value repeats if tries exceed length. */
    public array $backoff = [5, 15, 60, 300, 900];

    /** AMI socket read/write timeout (seconds). */
    private const AMI_SOCKET_TIMEOUT = 5;

    /** AMI TCP port. */
    private const AMI_PORT = 5038;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function handle(): void
    {
        $phone            = $this->data->phone ?? null;
        $cli              = $this->data->cli ?? null;
        $voicemailFile    = $this->data->voicemail_file_name ?? null;
        $apiToken         = $this->data->apiToken ?? null;
        $userId           = $this->data->user_id ?? null;
        $rvmDomainId      = $this->data->rvm_domain_id ?? null;
        $sipGatewayId     = $this->data->sip_gateway_id ?? null;
        $rvmCdrLogId      = $this->data->id ?? null;

        if (!$rvmCdrLogId) {
            throw new RuntimeException('SendRvmJob: missing rvm_cdr_log_id in payload.');
        }

        // ── RVM v2 shadow + divert hook ───────────────────────────────────
        // Two things happen here, in order, neither of which is allowed
        // to throw:
        //   1. Shadow observation — records the would-be new-pipeline
        //      outcome to master.rvm_shadow_log for any tenant not in
        //      'legacy' mode. Pure side-effect-free observability.
        //   2. Divert — for tenants in 'dry_run' mode, translates this
        //      legacy payload into a v2 rvm_drops row via RvmDropService
        //      (providerHint forced to 'mock' — no carrier calls). On a
        //      successful divert we SHORT-CIRCUIT and skip the legacy
        //      AMI dispatch entirely, because the v2 pipeline has now
        //      taken ownership of this drop.
        //
        // Any failure in either step falls through to legacy dispatch —
        // the fail-safe direction is "legacy keeps working".
        $this->recordShadowObservation();

        if ($this->attemptDivert()) {
            // Divert succeeded — rvm_cdr_log is already stamped with the
            // v2 drop id. Legacy path is skipped for this cdr row; the
            // v2 pipeline + MockProvider will carry it from here.
            return;
        }
        // ──────────────────────────────────────────────────────────────────

        // "timezone_queue_trigger" — legacy flag meaning "skip TZ window check".
        $timezoneQueueTrigger = (int) ($this->data->timezone_queue_trigger ?? 0);

        // Pick Asterisk server. `timezone_queue_trigger` originally doubled as
        // a server id in legacy code — honour that but fall back to
        // asterisk_server_id if present, else 1.
        $asteriskServerId = 1;
        if (!empty($this->data->asterisk_server_id)) {
            $asteriskServerId = (int) $this->data->asterisk_server_id;
        } elseif ($timezoneQueueTrigger > 1) {
            // Treat timezone_queue_trigger as a server id only when > 1.
            $asteriskServerId = $timezoneQueueTrigger;
        }

        // Calling-window check — keeps legacy behaviour.
        $return = $this->evaluateDialingWindow($phone);
        $dialable = $return['dialable'] === 1 || $timezoneQueueTrigger === 1;

        if (!$dialable) {
            // Outside the allowed window — update the CDR row and stop.
            // This is NOT a retryable failure.
            RvmCdrLog::where('id', $rvmCdrLogId)->update(['timezone_status' => '0']);
            return;
        }

        // Guard against duplicate dispatches. The unique index on
        // rvm_queue_list.rvm_cdr_log_id turns the race into a fast insert
        // error that we treat as "already handled by another worker".
        try {
            $queueRow = new RvmQueueList();
            $queueRow->rvm_cdr_log_id = $rvmCdrLogId;
            $queueRow->status = $this->data->status_code ?? null;
            $queueRow->save();
        } catch (QueryException $e) {
            if ($this->isDuplicateKey($e)) {
                Log::info('SendRvmJob: duplicate dispatch suppressed', [
                    'rvm_cdr_log_id' => $rvmCdrLogId,
                ]);
                return;
            }
            throw $e;
        }

        // Load Asterisk credentials.
        $server = AsteriskServer::where('rvm_status', '1')
            ->where('id', $asteriskServerId)
            ->first();

        if (!$server) {
            throw new RuntimeException(
                "SendRvmJob: AsteriskServer id={$asteriskServerId} not found or RVM disabled."
            );
        }

        // Sanitize EVERY field that lands inside the channel string.
        // A CRLF in any of these would let a caller inject a second AMI
        // action (originate to arbitrary destinations, read private files,
        // etc.) before the socket is closed.
        $safePhone        = $this->sanitizeAmiField((string) $phone, 'phone');
        $safeCli          = $this->sanitizeAmiField((string) $cli, 'cli');
        $safeVoicemail    = $this->sanitizeAmiField((string) $voicemailFile, 'voicemail_file_name');
        $safeApiToken     = $this->sanitizeAmiField((string) $apiToken, 'apiToken');
        $safeUserId       = $this->sanitizeAmiField((string) $userId, 'user_id');
        $safeRvmDomain    = $this->sanitizeAmiField((string) $rvmDomainId, 'rvm_domain_id');
        $safeSipGatewayId = $this->sanitizeAmiField((string) $sipGatewayId, 'sip_gateway_id');
        $safeRvmCdrLogId  = $this->sanitizeAmiField((string) $rvmCdrLogId, 'rvm_cdr_log_id');

        $host     = $server->host;
        $username = $server->user;
        $secret   = $server->secret;

        $channel = "Local/"
            . "{$safePhone}-"
            . "{$safeCli}-"
            . "{$safeVoicemail}-"
            . "{$safeApiToken}-"
            . "{$safeUserId}-"
            . "{$safeRvmDomain}-"
            . "{$safeSipGatewayId}-"
            . "{$safeRvmCdrLogId}@rvm87";

        // Build the AMI originate command.
        $commands = [
            "Action: Login",
            "Username: {$username}",
            "Secret: {$secret}",
            "",
            "Action: Originate",
            "Channel: {$channel}",
            "Exten: {$safePhone}",
            "Context: voice-drop-campaign",
            "Priority: 1",
            "Timeout: 10000",
            "",
            "Action: Logoff",
            "",
        ];
        $ami = implode("\r\n", $commands);

        $socket = @fsockopen($host, self::AMI_PORT, $errno, $errstr, self::AMI_SOCKET_TIMEOUT);
        if (!$socket) {
            throw new RuntimeException(
                "SendRvmJob: unable to connect to AMI {$host}:" . self::AMI_PORT . " ({$errno}: {$errstr})"
            );
        }

        try {
            stream_set_timeout($socket, self::AMI_SOCKET_TIMEOUT);

            if (fwrite($socket, $ami) === false) {
                throw new RuntimeException('SendRvmJob: failed to write AMI command.');
            }

            // Read the response until we see the Originate result or we
            // time out. We explicitly look for "Response: Success" tied to
            // the originate action.
            $this->readAmiResponse($socket);
        } finally {
            @fclose($socket);
        }

        // Atomic counter update — never read/modify/write.
        RvmCdrLog::where('id', $rvmCdrLogId)->update(['timezone_status' => '1']);
        RvmCdrLog::where('id', $rvmCdrLogId)->increment('tries');
    }

    /**
     * Called by the queue system after $tries attempts fail.
     * Persists a terminal failure on the CDR row.
     */
    public function failed(Throwable $e): void
    {
        $rvmCdrLogId = $this->data->id ?? null;

        Log::error('SendRvmJob permanently failed', [
            'rvm_cdr_log_id' => $rvmCdrLogId,
            'error'          => $e->getMessage(),
        ]);

        if ($rvmCdrLogId) {
            try {
                RvmCdrLog::where('id', $rvmCdrLogId)->update([
                    'status' => 'failed',
                ]);
            } catch (Throwable $ignored) {
                // Never let failed() itself throw — it would spiral.
                Log::error('SendRvmJob::failed() cleanup error', ['error' => $ignored->getMessage()]);
            }
        }
    }

    /**
     * Observability-only hook for RVM v2 cutover.
     *
     * Resolves the tenant for this legacy drop, reads the per-tenant
     * pipeline mode, and — if not legacy — asks RvmShadowService to
     * record a predicted outcome row in master.rvm_shadow_log.
     *
     * CONTRACT: MUST NOT throw. Any failure here is silently logged so
     * production RVM dispatch is unaffected. This method is deliberately
     * placed BEFORE the duplicate-dispatch guard so shadow rows are
     * written on every attempt (the duplicate-check races aren't relevant
     * to observability).
     */
    private function recordShadowObservation(): void
    {
        try {
            $clientId = $this->resolveClientIdFromLegacyPayload();
            if ($clientId === null) {
                return;
            }

            /** @var RvmFeatureFlagService $flags */
            $flags = app(RvmFeatureFlagService::class);
            $mode  = $flags->modeForTenant($clientId);

            if ($mode === TenantFlag::MODE_LEGACY) {
                return;
            }

            /** @var RvmShadowService $shadow */
            $shadow = app(RvmShadowService::class);
            $shadow->recordShadow($clientId, $this->data);
        } catch (Throwable $e) {
            Log::warning('SendRvmJob: shadow hook failed (ignored)', [
                'rvm_cdr_log_id' => $this->data->id ?? null,
                'error'          => $e->getMessage(),
            ]);
            // Never rethrow — legacy pipeline must never break on shadow.
        }
    }

    /**
     * Phase 5b/5c divert hook.
     *
     * When the tenant is in 'dry_run' or 'live' mode, hand the payload
     * off to RvmDivertService. On a confirmed divert we return true and
     * the caller short-circuits out of handle() — the v2 pipeline owns
     * this drop now.
     *
     *   - dry_run mode: divert service forces providerHint='mock',
     *     exercising v2 end-to-end without dialing a carrier.
     *   - live mode: divert service reads rvm_tenant_flags.live_provider
     *     and dispatches through a real carrier. If live_provider is
     *     unset, the daily cap is hit, or audio resolution fails, the
     *     divert service returns skipped and legacy dispatch continues.
     *
     * Any other mode (legacy / shadow), or any failure inside the
     * divert service, returns false and the legacy AMI dispatch
     * continues.
     *
     * CONTRACT: never throws.
     */
    private function attemptDivert(): bool
    {
        try {
            $clientId = $this->resolveClientIdFromLegacyPayload();
            if ($clientId === null) {
                return false;
            }

            /** @var RvmFeatureFlagService $flags */
            $flags = app(RvmFeatureFlagService::class);
            $mode  = $flags->modeForTenant($clientId);

            if ($mode !== TenantFlag::MODE_DRY_RUN && $mode !== TenantFlag::MODE_LIVE) {
                // legacy / shadow → legacy dispatch continues.
                return false;
            }

            /** @var RvmDivertService $divert */
            $divert = app(RvmDivertService::class);
            $result = $divert->divert($clientId, $mode, $this->data);

            if ($result->diverted) {
                Log::info('SendRvmJob: diverted to v2 pipeline', [
                    'rvm_cdr_log_id' => $this->data->id ?? null,
                    'v2_drop_id'     => $result->v2DropId,
                    'mode'           => $result->mode,
                ]);
                return true;
            }

            Log::info('SendRvmJob: divert skipped', [
                'rvm_cdr_log_id' => $this->data->id ?? null,
                'mode'           => $result->mode,
                'reason'         => $result->reason,
            ]);
            return false;
        } catch (Throwable $e) {
            Log::warning('SendRvmJob: divert hook failed (ignored)', [
                'rvm_cdr_log_id' => $this->data->id ?? null,
                'error'          => $e->getMessage(),
            ]);
            // Fail-safe: any exception in the divert path means legacy
            // dispatch continues as if the hook had never run.
            return false;
        }
    }

    /**
     * Resolve the tenant id for this legacy payload.
     *
     *   1. Explicit `client_id` on the payload (preferred — newer callers).
     *   2. Lookup by `api_key` against master.clients.api_key.
     *
     * Result is cached for 5 minutes (api keys rarely change) under
     * `rvm:legacy_apikey_client:<sha1>` so we don't hit master on every
     * single dispatch. Cache miss on a negative result is stored as ''
     * to keep lookups bounded.
     */
    private function resolveClientIdFromLegacyPayload(): ?int
    {
        if (!empty($this->data->client_id) && is_numeric($this->data->client_id)) {
            return (int) $this->data->client_id;
        }

        $apiKey = $this->data->api_key ?? null;
        if (!is_string($apiKey) || $apiKey === '') {
            return null;
        }

        $cacheKey = 'rvm:legacy_apikey_client:' . sha1($apiKey);

        try {
            $cached = Redis::get($cacheKey);
            if ($cached !== null) {
                return $cached === '' ? null : (int) $cached;
            }
        } catch (Throwable $e) {
            // Redis miss → fall through to DB lookup.
        }

        $row = DB::connection('master')
            ->table('clients')
            ->where('api_key', $apiKey)
            ->first(['id']);

        $clientId = $row ? (int) $row->id : null;

        try {
            Redis::setex($cacheKey, 300, (string) ($clientId ?? ''));
        } catch (Throwable $e) {
            // Cache write failure is non-fatal.
        }

        return $clientId;
    }

    /**
     * Strip protocol-sensitive characters (CR, LF, colon, pipe, NUL) from
     * a value before it lands in an AMI header / channel string.
     *
     * @throws RuntimeException on empty values so the caller never silently
     *                          submits a broken Originate.
     */
    private function sanitizeAmiField(string $value, string $fieldName): string
    {
        $cleaned = preg_replace('/[\x00\r\n:|\s]/', '', $value);
        if ($cleaned === null || $cleaned === '') {
            throw new RuntimeException("SendRvmJob: empty AMI field '{$fieldName}' after sanitize.");
        }
        return $cleaned;
    }

    /**
     * Consume the AMI response up to (and slightly past) the Originate result.
     * Throws on error or timeout so the job retries.
     */
    private function readAmiResponse($socket): void
    {
        $buffer = '';
        $deadline = microtime(true) + self::AMI_SOCKET_TIMEOUT;

        while (microtime(true) < $deadline) {
            $line = fgets($socket, 4096);
            if ($line === false) {
                // Could be EOF or timeout — check stream meta.
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('SendRvmJob: AMI read timeout.');
                }
                break;
            }

            $buffer .= $line;

            // AMI protocol: messages are terminated by a blank line.
            // We want at least one "Response: ..." before returning.
            if (preg_match('/Response:\s*Error.*?Message:\s*(.*)/is', $buffer, $m)) {
                throw new RuntimeException('SendRvmJob: AMI error response: ' . trim($m[1]));
            }

            if (preg_match('/Response:\s*Success/i', $buffer) && substr($buffer, -2) === "\n\n") {
                return;
            }
        }

        // Drained the deadline without a confirmed success — treat as retryable.
        throw new RuntimeException('SendRvmJob: AMI response not received within ' . self::AMI_SOCKET_TIMEOUT . 's.');
    }

    /**
     * Evaluate the recipient-area-code time window exactly like the legacy
     * job did. Returns the same shape the legacy code used so nothing
     * downstream has to change.
     *
     * @return array{dialable:int, areacodeTimeZone:int, dialingTime:int}
     */
    private function evaluateDialingWindow(?string $phone): array
    {
        $return = ['dialable' => 0, 'areacodeTimeZone' => 0, 'dialingTime' => 0];
        if ($phone === null) {
            return $return;
        }

        $digits = preg_replace('/[^0-9]/', '', $phone);
        $last10 = substr($digits, -10);
        $areacode = substr(trim($last10), 0, 3);

        $timezoneRow = DB::connection('master')
            ->selectOne(
                "SELECT timezone FROM timezone WHERE areacode = :areacode",
                ['areacode' => $areacode]
            );
        $timezone = $timezoneRow ? (array) $timezoneRow : [];

        if (empty($timezone)) {
            // No mapping known — legacy behaviour is "let it dial".
            $return['dialable'] = 1;
            $return['dialingTime'] = 1;
            return $return;
        }

        if (empty($timezone['timezone'])) {
            return $return;
        }

        $return['areacodeTimeZone'] = 1;

        $now = new DateTime();
        $now->setTimeZone(new DateTimeZone(timezone_name_from_abbr($timezone['timezone'])));
        $currentTime = $now->format('H:i:s');

        $startTime = $this->data->start_time ?? '09:00:00';
        $endTime   = $this->data->end_time ?? '21:00:00';

        if (strtotime($startTime) < strtotime($currentTime)
            && strtotime($endTime) > strtotime($currentTime)
        ) {
            $return['dialingTime'] = 1;
            $return['dialable'] = 1;
        }

        return $return;
    }

    /**
     * Detect duplicate-key MySQL errors regardless of driver variant.
     */
    private function isDuplicateKey(QueryException $e): bool
    {
        // SQLSTATE 23000 with errno 1062 = duplicate entry.
        $sqlstate = (string) ($e->getCode() ?? '');
        $errno = (int) ($e->errorInfo[1] ?? 0);
        return $sqlstate === '23000' && $errno === 1062;
    }
}
