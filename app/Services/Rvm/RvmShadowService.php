<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\Drop;
use App\Model\Master\Rvm\ShadowLog;
use App\Services\Rvm\Exceptions\DncBlockedException;
use App\Services\Rvm\Exceptions\QuietHoursException;
use App\Services\Rvm\Exceptions\ProviderUnavailableException;
use App\Services\Rvm\Support\PhoneNormalizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Observability-only recorder for shadow mode.
 *
 * Given a legacy RVM payload (from SendRvmJob::$data), this service runs
 * the *new* compliance + routing pipeline in a pure, side-effect-free way
 * and writes the outcome to `rvm_shadow_log`.
 *
 * STRICT CONTRACT: `recordShadow()` MUST NOT throw. The legacy pipeline
 * calls into this from the top of `SendRvmJob::handle()` — any unhandled
 * exception here would break production RVM dispatch. Every code path is
 * wrapped in try/catch and failures are Log::warning'd.
 *
 * What it does NOT do:
 *   - No wallet reserve / commit / refund
 *   - No queued/deferred/delivered Event rows
 *   - No webhook dispatch
 *   - No Drop row persisted to rvm_drops
 *
 * What it DOES do:
 *   - Calls RvmComplianceService::assertCompliant() and catches violations
 *   - Calls RvmProviderRouter::pickProvider() with a transient in-memory Drop
 *   - Records the predicted provider + cost + rejection reason
 */
class RvmShadowService
{
    public function __construct(
        private RvmComplianceService $compliance,
        private RvmProviderRouter $router,
    ) {
    }

    /**
     * Write a single shadow_log row for a legacy drop that's about to dispatch.
     * Never throws.
     *
     * @param int          $clientId  Tenant id (resolved by caller from api_token).
     * @param object|array $legacyPayload  SendRvmJob::$data — object or array shape.
     */
    public function recordShadow(int $clientId, $legacyPayload): void
    {
        try {
            $payload = $this->normalizePayload($legacyPayload);

            $phoneE164 = $this->safeNormalizePhone($payload['phone'] ?? '');
            if ($phoneE164 === null) {
                // Without a usable phone we can't simulate anything — still
                // log a minimal row so divergences don't vanish silently.
                $this->writeRow([
                    'client_id'             => $clientId,
                    'legacy_rvm_cdr_log_id' => $this->intOrNull($payload['id'] ?? null),
                    'phone_e164'            => (string) ($payload['phone'] ?? ''),
                    'caller_id'             => $this->stringOrNull($payload['cli'] ?? null),
                    'legacy_dispatched_at'  => Carbon::now(),
                    'would_dispatch'        => false,
                    'would_provider'        => null,
                    'would_cost_cents'      => null,
                    'would_reject_reason'   => 'invalid_phone',
                    'divergence_flags'      => ['phone_normalize_failed' => true],
                    'legacy_payload'        => $payload,
                ]);
                return;
            }

            [$wouldDispatch, $rejectReason] = $this->simulateCompliance($clientId, $phoneE164);
            [$wouldProvider, $wouldCostCents, $routingError] = $this->simulateRouting(
                $clientId,
                $phoneE164,
                $this->stringOrNull($payload['cli'] ?? null),
            );

            $flags = [];
            if (!$wouldDispatch) {
                $flags['compliance_would_reject'] = true;
            }
            if ($routingError) {
                $flags['routing_error'] = $routingError;
            }
            if ($wouldDispatch && !$wouldProvider) {
                $flags['no_provider_candidate'] = true;
            }

            $this->writeRow([
                'client_id'             => $clientId,
                'legacy_rvm_cdr_log_id' => $this->intOrNull($payload['id'] ?? null),
                'phone_e164'            => $phoneE164,
                'caller_id'             => $this->stringOrNull($payload['cli'] ?? null),
                'legacy_dispatched_at'  => Carbon::now(),
                'would_dispatch'        => $wouldDispatch,
                'would_provider'        => $wouldProvider,
                'would_cost_cents'      => $wouldCostCents,
                'would_reject_reason'   => $rejectReason,
                'divergence_flags'      => $flags ?: null,
                'legacy_payload'        => $payload,
            ]);
        } catch (Throwable $e) {
            Log::warning('RvmShadowService::recordShadow failed', [
                'client_id' => $clientId,
                'error'     => $e->getMessage(),
                'trace'     => substr($e->getTraceAsString(), 0, 1000),
            ]);
            // Swallow — never break the legacy flow.
        }
    }

    /**
     * Run RvmComplianceService::assertCompliant() and catch the two known
     * rejection types. Returns [wouldDispatch, rejectReason].
     *
     * @return array{0: bool, 1: string|null}
     */
    private function simulateCompliance(int $clientId, string $phoneE164): array
    {
        try {
            $quietStart = (string) config('rvm.compliance.quiet_start', '09:00:00');
            $quietEnd   = (string) config('rvm.compliance.quiet_end', '20:00:00');

            $this->compliance->assertCompliant(
                clientId: $clientId,
                phoneE164: $phoneE164,
                respectDnc: (bool) config('rvm.compliance.respect_global_dnc', true),
                respectQuietHours: true,
                quietStart: $quietStart,
                quietEnd: $quietEnd,
            );
            return [true, null];
        } catch (DncBlockedException $e) {
            return [false, 'dnc_blocked'];
        } catch (QuietHoursException $e) {
            return [false, 'quiet_hours'];
        } catch (Throwable $e) {
            // Any other compliance exception counts as "would reject" —
            // record its class for diagnosis.
            return [false, 'compliance_error:' . class_basename($e)];
        }
    }

    /**
     * Run RvmProviderRouter::pickProvider() on a transient Drop (no save)
     * and return the would-be provider + cost, or null on failure.
     *
     * @return array{0: string|null, 1: int|null, 2: string|null}
     */
    private function simulateRouting(int $clientId, string $phoneE164, ?string $callerId): array
    {
        try {
            $transient = new Drop([
                'client_id'    => $clientId,
                'phone_e164'   => $phoneE164,
                'caller_id'    => $callerId,
                'status'       => 'queued',
                'priority'     => 'normal',
            ]);

            $provider = $this->router->pickProvider($transient);
            $cost = (int) $provider->estimateCost($transient);
            return [$provider->name(), $cost, null];
        } catch (ProviderUnavailableException $e) {
            return [null, null, 'no_healthy_provider'];
        } catch (Throwable $e) {
            return [null, null, 'routing_error:' . class_basename($e)];
        }
    }

    /**
     * Persist a shadow_log row. Isolated so the unit test can mock writes.
     */
    private function writeRow(array $attrs): void
    {
        ShadowLog::on('master')->create($attrs);
    }

    /**
     * SendRvmJob hands us $this->data which may be stdClass or array.
     * Normalise to an associative array once so the rest of this class
     * never does defensive casting.
     */
    private function normalizePayload($payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }
        if (is_object($payload)) {
            // stdClass → array. json round-trip handles nested stdClass too.
            return json_decode(json_encode($payload), true) ?? [];
        }
        return [];
    }

    private function safeNormalizePhone($raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            return PhoneNormalizer::toE164($raw);
        } catch (Throwable $e) {
            return null;
        }
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
