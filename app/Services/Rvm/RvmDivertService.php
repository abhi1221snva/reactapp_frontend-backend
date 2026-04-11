<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\TenantFlag;
use App\Services\Rvm\DTO\DivertResult;
use App\Services\Rvm\DTO\DropRequest;
use App\Services\Rvm\DTO\Priority;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Legacy → v2 traffic diverter.
 *
 * RvmShadowService *observes* legacy dispatches. RvmDivertService
 * *intercepts* them — when a tenant's pipeline_mode is dry_run or live,
 * SendRvmJob calls divert() right after the shadow hook, and — on
 * success — skips the legacy AMI dispatch entirely.
 *
 * STRICT CONTRACT: divert() MUST NOT throw. Every failure path returns
 * DivertResult::skipped(...). The calling SendRvmJob interprets
 * `$result->diverted === false` as "continue with legacy", so a buggy
 * divert service can never take down the legacy pipeline.
 *
 * Scope:
 *   - Phase 5b — dry_run mode. Forces providerHint='mock', so the full
 *     v2 pipeline runs end-to-end (rate-limit, compliance, wallet
 *     reserve, drop persist, events, webhook enqueue) but the drop is
 *     dispatched through MockProvider — no carrier calls, no real
 *     money spent.
 *   - Phase 5c — live mode. Reads rvm_tenant_flags.live_provider as
 *     the carrier hint, enforces rvm_tenant_flags.live_daily_cap as a
 *     hard safety cap per UTC day, resolves a playable audio URL (per-
 *     drop metadata, voice_templete.audio_file_url, or the legacy
 *     voicemail_file_name if already a URL), and — on success — the v2
 *     pipeline dispatches through a real carrier.
 *
 * Idempotency:
 *   - diverted_at on rvm_cdr_log is the authoritative at-most-once
 *     marker. A row with diverted_at != NULL is skipped with reason
 *     'already_diverted'. This protects against job retries and against
 *     the backfill command racing with a live worker on the same row.
 */
class RvmDivertService
{
    /** Forced provider for dry_run mode — exercises v2 without dialing. */
    private const DRY_RUN_PROVIDER = 'mock';

    public function __construct(
        private RvmDropService $drops,
    ) {}

    /**
     * Attempt to divert a legacy SendRvmJob payload into the v2 pipeline.
     *
     * @param int          $clientId  Tenant id (already resolved by caller).
     * @param string       $mode      TenantFlag::MODE_* value.
     * @param object|array $payload   SendRvmJob::$data shape.
     */
    public function divert(int $clientId, string $mode, $payload): DivertResult
    {
        try {
            // ── Only dry_run + live are wired ──────────────────────────
            if ($mode !== TenantFlag::MODE_DRY_RUN && $mode !== TenantFlag::MODE_LIVE) {
                return DivertResult::skipped('mode_not_diverted', $mode);
            }

            $data = $this->normalizePayload($payload);

            $cdrId = $this->intOrNull($data['id'] ?? null);
            if ($cdrId === null) {
                return DivertResult::skipped('missing_cdr_id', $mode);
            }

            // At-most-once guard — don't double-divert on retries or backfill races.
            if ($this->alreadyDiverted($cdrId)) {
                return DivertResult::skipped('already_diverted', $mode);
            }

            // Live mode pulls its provider + daily cap + audit trail
            // from rvm_tenant_flags. Dry-run ignores the flag entirely
            // and uses the mock provider unconditionally.
            $flag = $mode === TenantFlag::MODE_LIVE
                ? TenantFlag::on('master')->find($clientId)
                : null;

            if ($mode === TenantFlag::MODE_LIVE) {
                if (!$flag || empty($flag->live_provider)) {
                    return DivertResult::skipped('live_provider_not_set', $mode);
                }
                if ($this->liveDailyCapReached($clientId, $flag)) {
                    return DivertResult::skipped('live_daily_cap_reached', $mode);
                }
            }

            $req = $this->buildDropRequest($clientId, $data, $mode, $flag);
            if ($req === null) {
                // buildDropRequest returns null for two distinct reasons
                // in live mode — missing phone/cli OR unresolvable audio.
                // We differentiate via a secondary probe so the caller
                // gets an actionable reason code.
                $reason = ($mode === TenantFlag::MODE_LIVE && $this->hasPhoneAndCli($data))
                    ? 'no_audio_for_live'
                    : 'translate_failed';
                return DivertResult::skipped($reason, $mode);
            }

            // Idempotency key scoped to (client, cdr) so replays by the
            // queue return the same v2 drop without re-reserving wallet.
            $idemKey = 'legacy_cdr:' . $clientId . ':' . $cdrId;

            try {
                $drop = $this->drops->createDrop(
                    clientId: $clientId,
                    req: $req,
                    idempotencyKey: $idemKey,
                    userId: $this->intOrNull($data['user_id'] ?? null),
                    apiKeyId: null,
                );
            } catch (Throwable $e) {
                Log::warning('RvmDivertService: createDrop rejected payload', [
                    'client_id' => $clientId,
                    'cdr_id'    => $cdrId,
                    'mode'      => $mode,
                    'error'     => $e->getMessage(),
                ]);
                return DivertResult::skipped(
                    'dropservice_rejected:' . class_basename($e),
                    $mode,
                );
            }

            $this->markCdrDiverted($cdrId, (string) $drop->id, $mode);

            if ($mode === TenantFlag::MODE_LIVE) {
                // Live diverts touch real carriers + real money — log at
                // INFO so they show up in the normal app log stream
                // without having to enable debug.
                Log::info('RvmDivertService: live divert succeeded', [
                    'client_id'  => $clientId,
                    'cdr_id'     => $cdrId,
                    'v2_drop_id' => (string) $drop->id,
                    'provider'   => $flag?->live_provider,
                ]);
            }

            return DivertResult::diverted((string) $drop->id, $mode);
        } catch (Throwable $e) {
            // Absolute last-resort catch — the legacy pipeline must never
            // die because of a bug in this service.
            Log::error('RvmDivertService: unexpected exception (ignored)', [
                'client_id' => $clientId,
                'mode'      => $mode,
                'error'     => $e->getMessage(),
                'trace'     => substr($e->getTraceAsString(), 0, 800),
            ]);
            return DivertResult::skipped('unexpected_exception', $mode);
        }
    }

    /**
     * Has this cdr row already been diverted by a previous attempt?
     *
     * Uses a direct SELECT against master.rvm_cdr_log rather than the
     * RvmCdrLog Eloquent model so we don't pay the hydration cost on
     * every dispatch.
     */
    private function alreadyDiverted(int $cdrId): bool
    {
        try {
            $row = DB::connection('master')
                ->table('rvm_cdr_log')
                ->where('id', $cdrId)
                ->first(['diverted_at']);
            return $row && $row->diverted_at !== null;
        } catch (Throwable $e) {
            Log::warning('RvmDivertService: alreadyDiverted() check failed', [
                'cdr_id' => $cdrId,
                'error'  => $e->getMessage(),
            ]);
            // Fail closed — on any DB hiccup, claim "already diverted" to
            // bail out of the divert path. The legacy pipeline will run
            // and the next retry can try again.
            return true;
        }
    }

    /**
     * Translate a legacy SendRvmJob payload into a v2 DropRequest.
     *
     * The legacy payload shape is loose — fields are optional and often
     * missing. We pass through whatever is present, synthesize sensible
     * defaults for the rest, and stash the raw legacy fields under
     * metadata.legacy so downstream debugging + the callback controller
     * (audio_url resolver) can see them.
     *
     * Returns null if the payload is missing something that would make
     * createDrop() definitely reject (phone, caller id) OR — in live
     * mode — if we cannot resolve an audio URL to hand the carrier.
     * The caller disambiguates the two null cases via hasPhoneAndCli().
     */
    private function buildDropRequest(int $clientId, array $data, string $mode, ?TenantFlag $flag): ?DropRequest
    {
        if (!$this->hasPhoneAndCli($data)) {
            return null;
        }
        $phone    = (string) $data['phone'];
        $callerId = (string) $data['cli'];

        // voice_template_id is a required int on DropRequest. Legacy
        // payloads carry `voicemail_id` (string) or none at all — coerce
        // to an int if possible, else fall back to 0 so dispatch can
        // proceed (the Mock provider ignores it entirely).
        $voiceTemplateId = 0;
        if (isset($data['voicemail_id']) && is_numeric($data['voicemail_id'])) {
            $voiceTemplateId = (int) $data['voicemail_id'];
        } elseif (isset($data['voice_template_id']) && is_numeric($data['voice_template_id'])) {
            $voiceTemplateId = (int) $data['voice_template_id'];
        }

        // Provider selection:
        //   - dry_run forces 'mock' (safety rail — never dials a carrier)
        //   - live reads rvm_tenant_flags.live_provider (required by caller)
        $providerHint = $mode === TenantFlag::MODE_DRY_RUN
            ? self::DRY_RUN_PROVIDER
            : ($flag?->live_provider ?: null);

        // Audio URL resolution:
        //   - dry_run doesn't care — MockProvider ignores audio entirely.
        //   - live MUST have an audio URL, or the callback controller will
        //     either return 404 (slybroadcast) or fall back to generic
        //     TTS (twilio/plivo). We refuse to divert in live mode when
        //     no audio is resolvable — the legacy pipeline keeps handling
        //     the row and the operator can fix the config without losing
        //     traffic.
        $audioUrl = $this->resolveAudioUrlForDivert($clientId, $data, $voiceTemplateId);
        if ($mode === TenantFlag::MODE_LIVE && $audioUrl === null) {
            return null;
        }

        // Preserve every legacy-relevant field for the audit trail — the
        // callback controller's audio resolver already knows how to read
        // metadata.audio_url / metadata.tts_text, and future tooling can
        // mine metadata.legacy for migration debugging.
        $metadata = [
            'divert'        => [
                'source'        => 'SendRvmJob',
                'mode'          => $mode,
                'at'            => Carbon::now()->toIso8601String(),
                'legacy_cdr_id' => $data['id'] ?? null,
            ],
            'legacy' => [
                'voicemail_file_name' => $data['voicemail_file_name'] ?? null,
                'voicemail_id'        => $data['voicemail_id']        ?? null,
                'api_token'           => $data['apiToken']            ?? null,
                'rvm_domain_id'       => $data['rvm_domain_id']       ?? null,
                'sip_gateway_id'      => $data['sip_gateway_id']      ?? null,
                'asterisk_server_id'  => $data['asterisk_server_id']  ?? null,
                'user_id'             => $data['user_id']             ?? null,
            ],
        ];
        if ($audioUrl !== null) {
            $metadata['audio_url'] = $audioUrl;
        }

        return new DropRequest(
            phone: $phone,
            callerId: $callerId,
            voiceTemplateId: $voiceTemplateId,
            priority: Priority::NORMAL,
            providerHint: $providerHint,
            // Legacy has no concept of campaign_id in the v2 sense.
            campaignId: null,
            scheduledAt: null,
            // Legacy already ran its own timezone check upstream of this
            // point (see SendRvmJob::evaluateDialingWindow). Honour that
            // decision by skipping the v2 quiet-hours gate so we don't
            // double-reject rows the legacy side already green-lit.
            respectQuietHours: false,
            timezoneStrategy: 'lead',
            callbackUrl: null,
            metadata: $metadata,
        );
    }

    /**
     * Simple predicate used by both the divert flow and the caller's
     * reason-code disambiguation.
     */
    private function hasPhoneAndCli(array $data): bool
    {
        return $this->stringOrNull($data['phone'] ?? null) !== null
            && $this->stringOrNull($data['cli']   ?? null) !== null;
    }

    /**
     * Resolve a playable audio URL for the divert.
     *
     * Precedence (first match wins):
     *   1. Legacy payload `voicemail_file_name` if already a fully-qualified
     *      http(s) URL — some newer callers already send this.
     *   2. env('RVM_LEGACY_AUDIO_BASE_URL') + voicemail_file_name if the
     *      legacy field is a relative filename and ops have published
     *      the Asterisk sound library behind an HTTP frontend.
     *   3. Per-tenant voice_templete.audio_file_url (same column the
     *      callback controller reads for live drops created via the v2
     *      API — keeps resolution consistent across code paths).
     *
     * Returns null if no match — in live mode that aborts the divert.
     * In dry_run mode the null is accepted and the drop is created with
     * no audio_url in metadata (the mock provider doesn't care).
     */
    private function resolveAudioUrlForDivert(int $clientId, array $data, int $voiceTemplateId): ?string
    {
        // 1. Legacy payload already carries a URL?
        $fileName = $this->stringOrNull($data['voicemail_file_name'] ?? null);
        if ($fileName !== null && preg_match('#^https?://#i', $fileName)) {
            return $fileName;
        }

        // 2. Relative filename + configured base URL?
        $base = (string) env('RVM_LEGACY_AUDIO_BASE_URL', '');
        if ($fileName !== null && $base !== '') {
            return rtrim($base, '/') . '/' . ltrim($fileName, '/');
        }

        // 3. voice_templete row on the tenant's client DB.
        if ($voiceTemplateId > 0) {
            try {
                $conn = 'mysql_' . $clientId;
                $row = DB::connection($conn)
                    ->table('voice_templete')
                    ->where('templete_id', $voiceTemplateId)
                    ->first();
                if ($row) {
                    if (!empty($row->audio_file_url)) {
                        return (string) $row->audio_file_url;
                    }
                    if (!empty($row->audio_url)) {
                        return (string) $row->audio_url;
                    }
                }
            } catch (Throwable $e) {
                Log::warning('RvmDivertService: voice_templete lookup failed', [
                    'client_id'         => $clientId,
                    'voice_template_id' => $voiceTemplateId,
                    'error'             => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * Has this tenant already hit their live_daily_cap for today?
     *
     * Counts rvm_drops for the tenant created since UTC midnight with
     * a divert mode of 'live' in metadata. A no-cap flag (null cap)
     * always returns false.
     *
     * Fails closed: on any DB hiccup returns true (block further live
     * diverts) so a broken count query can never blow past a cap.
     */
    private function liveDailyCapReached(int $clientId, TenantFlag $flag): bool
    {
        $cap = $flag->live_daily_cap;
        if ($cap === null || $cap <= 0) {
            return false;
        }

        try {
            // Count rvm_drops directly — cheaper than joining cdr_log +
            // clients, and the client_id column on rvm_drops is the
            // authoritative tenant scope for v2 drops. We filter on a
            // metadata.divert.mode JSON probe so dry_run diverts (on
            // the same tenant, unlikely but possible) don't pollute
            // the live count.
            $count = DB::connection('master')
                ->table('rvm_drops')
                ->where('client_id', $clientId)
                ->where('created_at', '>=', Carbon::now('UTC')->startOfDay())
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.divert.mode')) = ?", [TenantFlag::MODE_LIVE])
                ->count();

            return $count >= (int) $cap;
        } catch (Throwable $e) {
            Log::warning('RvmDivertService: live_daily_cap check failed, blocking divert', [
                'client_id' => $clientId,
                'cap'       => $cap,
                'error'     => $e->getMessage(),
            ]);
            return true;
        }
    }

    /**
     * Stamp rvm_cdr_log with the v2 drop id + divert mode + timestamp.
     *
     * A narrow UPDATE with a diverted_at IS NULL predicate ensures a
     * racing worker cannot overwrite an existing divert. If the UPDATE
     * affects zero rows it means we lost the race — not a problem,
     * because the winning worker already did the same work.
     */
    private function markCdrDiverted(int $cdrId, string $v2DropId, string $mode): void
    {
        try {
            DB::connection('master')
                ->table('rvm_cdr_log')
                ->where('id', $cdrId)
                ->whereNull('diverted_at')
                ->update([
                    'v2_drop_id'  => $v2DropId,
                    'divert_mode' => $mode,
                    'diverted_at' => Carbon::now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('RvmDivertService: markCdrDiverted failed', [
                'cdr_id'     => $cdrId,
                'v2_drop_id' => $v2DropId,
                'error'      => $e->getMessage(),
            ]);
            // Don't rethrow — the drop is already in v2. Worst case the
            // operator sees a v2 drop without a legacy pointer; the
            // backfill command can repair it on the next pass.
        }
    }

    /**
     * SendRvmJob hands us either stdClass or array. Normalise to an
     * associative array once so the rest of this class never defends
     * against both shapes.
     */
    private function normalizePayload($payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_object($payload)) {
            return json_decode(json_encode($payload), true) ?? [];
        }
        return [];
    }

    private function intOrNull($v): ?int
    {
        if ($v === null || $v === '') return null;
        return is_numeric($v) ? (int) $v : null;
    }

    private function stringOrNull($v): ?string
    {
        if ($v === null) return null;
        $s = (string) $v;
        return $s === '' ? null : $s;
    }
}
