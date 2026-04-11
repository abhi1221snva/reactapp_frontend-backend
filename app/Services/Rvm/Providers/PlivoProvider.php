<?php

namespace App\Services\Rvm\Providers;

use App\Model\Master\Rvm\Drop;
use App\Model\PlivoAccount;
use App\Services\Rvm\DTO\CallbackResult;
use App\Services\Rvm\DTO\DeliveryResult;
use App\Services\Rvm\DTO\HealthStatus;
use App\Services\Rvm\Exceptions\ProviderPermanentError;
use App\Services\Rvm\Exceptions\ProviderTransientError;
use Illuminate\Support\Facades\Log;
use Plivo\Exceptions\PlivoAuthenticationException;
use Plivo\Exceptions\PlivoRestException;
use Plivo\Exceptions\PlivoValidationException;
use Plivo\RestClient as PlivoClient;

/**
 * Plivo RVM driver.
 *
 * Delivery model:
 *   1. Originate an outbound call via Plivo's REST API with
 *      machine_detection="true". Plivo dials the destination, waits ~5s
 *      after answer to analyse the audio, then POSTs our answer_url with
 *      Machine=true/false.
 *   2. Our answer_url (phase 5a.4) returns Plivo XML:
 *        - Machine=true  → <Play> the voicemail audio, then <Hangup/>
 *        - Machine=false → <Hangup/> immediately (not our target)
 *   3. Plivo POSTs hangup_url with the final call state — handleCallback()
 *      parses that payload to mark the drop delivered/failed.
 *
 * The outcome only arrives via callback, so deliver() returns
 * DeliveryResult::dispatching() with Plivo's request_uuid as the
 * provider_message_id. Plivo's hangup webhook includes RequestUUID,
 * which is how we correlate back to the originating drop without having
 * to carry extra state between the API call and the callback.
 *
 * Credentials are resolved per-tenant via PlivoAccount::resolveCredentials()
 * — the same AES-decrypted pipeline used by PlivoService::forClient.
 */
class PlivoProvider implements RvmProviderInterface
{
    private const DRIVER_NAME = 'plivo';

    /** Milliseconds Plivo is allowed to spend analysing the audio for a machine. */
    private const AMD_TIMEOUT_MS = 5000;

    /** Ring timeout in seconds before Plivo gives up. */
    private const RING_TIMEOUT_SECONDS = 45;

    /**
     * Plivo REST error conditions that are non-retryable.
     *
     * Plivo returns HTTP 4xx for these with a descriptive message. The
     * codes themselves are not always stable across API versions, so we
     * match on HTTP status via the exception type below (401/403/422).
     */
    public function name(): string
    {
        return self::DRIVER_NAME;
    }

    public function supports(Drop $drop): bool
    {
        // Plivo accepts E.164 in digits-only form (no '+'), but we allow
        // both — deliver() strips the prefix before handing off. Here we
        // just sanity-check the shape.
        return (bool) preg_match('/^\+?\d{8,15}$/', (string) $drop->phone_e164);
    }

    public function estimateCost(Drop $drop): int
    {
        // Plivo outbound voice is ~0.85¢/min base + machine-detection
        // surcharge. A typical drop runs 10–20s → 3¢ reserve is safe.
        // Final cost is reconciled from the hangup webhook's TotalCost.
        $override = env('RVM_PLIVO_COST_CENTS');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }
        return (int) config('rvm.default_cost_cents', 2) + 1;
    }

    public function deliver(Drop $drop): DeliveryResult
    {
        if (!$drop->client_id) {
            throw new ProviderPermanentError('PlivoProvider: drop has no client_id');
        }
        if (!$drop->caller_id) {
            throw new ProviderPermanentError('PlivoProvider: drop has no caller_id');
        }
        if (!$drop->phone_e164) {
            throw new ProviderPermanentError('PlivoProvider: drop has no phone_e164');
        }

        $client = $this->clientFor((int) $drop->client_id);

        // Plivo expects digits-only phone numbers (no leading '+').
        $from = ltrim($drop->caller_id, '+');
        $to   = ltrim($drop->phone_e164, '+');

        $answerUrl = $this->buildSignedUrl('rvm/plivo/answer/' . $drop->id);
        $hangupUrl = $this->buildSignedUrl('rvm/plivo/status/' . $drop->id);

        $optionalArgs = [
            'answer_method'          => 'POST',
            'hangup_url'             => $hangupUrl,
            'hangup_method'          => 'POST',
            'machine_detection'      => 'true',
            'machine_detection_time' => self::AMD_TIMEOUT_MS,
            'ring_timeout'           => self::RING_TIMEOUT_SECONDS,
        ];

        try {
            $response = $client->calls->create(
                $from,
                [$to],
                $answerUrl,
                $optionalArgs
            );
        } catch (PlivoAuthenticationException $e) {
            throw new ProviderPermanentError(
                "PlivoProvider: authentication failed for client {$drop->client_id}: {$e->getMessage()}"
            );
        } catch (PlivoValidationException $e) {
            throw new ProviderPermanentError(
                "PlivoProvider: validation failed: {$e->getMessage()}"
            );
        } catch (PlivoRestException $e) {
            // Treat 4xx as permanent (bad request / forbidden), 5xx/0 as transient.
            $http = method_exists($e, 'getHttpStatus') ? (int) $e->getHttpStatus() : (int) $e->getCode();
            if ($http >= 400 && $http < 500) {
                throw new ProviderPermanentError(
                    "PlivoProvider: REST error {$http}: {$e->getMessage()}"
                );
            }
            throw new ProviderTransientError(
                "PlivoProvider: REST error {$http}: {$e->getMessage()}"
            );
        } catch (\Throwable $e) {
            // Transport-level (TCP, TLS, DNS) — retry.
            throw new ProviderTransientError('PlivoProvider: ' . $e->getMessage());
        }

        // Plivo's response->requestUuid can be a string or an array
        // (multi-destination dial). Since we only dial one number we
        // always take the first element.
        $rawUuid = $response->requestUuid ?? null;
        $requestUuid = is_array($rawUuid) ? ($rawUuid[0] ?? null) : $rawUuid;

        if (!$requestUuid) {
            throw new ProviderTransientError(
                'PlivoProvider: Plivo did not return a request_uuid'
            );
        }

        return DeliveryResult::dispatching(
            externalId: (string) $requestUuid,
            costCents: $this->estimateCost($drop),
            raw: [
                'request_uuid' => (string) $requestUuid,
                'message'      => $response->message ?? null,
                'api_id'       => $response->apiId ?? null,
            ],
        );
    }

    public function handleCallback(array $payload, array $headers): CallbackResult
    {
        // Phase 5a.4 route handler already verified X-Plivo-Signature
        // before dispatching here — trust the payload.
        //
        // Plivo's hangup webhook includes both CallUUID and RequestUUID;
        // we match against RequestUUID because that's what deliver() stored
        // as provider_message_id (the CallUUID only exists once the call
        // is placed, which happens after we return from deliver()).
        $requestUuid = $payload['RequestUUID']    ?? $payload['ALegRequestUUID'] ?? null;
        $callUuid    = $payload['CallUUID']       ?? null;
        $callStatus  = $payload['CallStatus']     ?? null;
        $hangupCause = $payload['HangupCause']    ?? null;
        $machineStr  = $payload['Machine']        ?? null;
        $billDurStr  = $payload['BillDuration']   ?? null;
        $totalCost   = $payload['TotalCost']      ?? null;

        if (!$callStatus) {
            return CallbackResult::ignored();
        }

        // Prefer RequestUUID lookup (set by deliver()); fall back to
        // CallUUID in case the answer_url handler has already upgraded
        // the drop to store it.
        $drop = null;
        if ($requestUuid) {
            $drop = Drop::on('master')
                ->where('provider', self::DRIVER_NAME)
                ->where('provider_message_id', (string) $requestUuid)
                ->first();
        }
        if (!$drop && $callUuid) {
            $drop = Drop::on('master')
                ->where('provider', self::DRIVER_NAME)
                ->where('provider_message_id', (string) $callUuid)
                ->first();
        }

        if (!$drop) {
            Log::warning('PlivoProvider: callback for unknown drop', [
                'request_uuid' => $requestUuid,
                'call_uuid'    => $callUuid,
                'status'       => $callStatus,
            ]);
            return CallbackResult::ignored();
        }

        // Map Plivo terminal states → drop statuses.
        //   - completed + Machine=true + BillDuration>0 → delivered
        //   - completed + Machine=false                 → failed (human)
        //   - busy / no-answer / failed                 → failed
        $isMachine = is_string($machineStr) && strtolower($machineStr) === 'true';
        $billDur   = (int) ($billDurStr ?? 0);

        $newStatus = null;
        $errorCode = null;
        $errorMsg  = null;

        $normalized = strtolower((string) $callStatus);

        if ($normalized === 'completed') {
            if ($isMachine && $billDur > 0) {
                $newStatus = 'delivered';
            } else {
                $newStatus = 'failed';
                $errorCode = $isMachine ? 'zero_bill_duration' : 'human_answer';
                $errorMsg  = "Plivo call completed but not delivered (machine={$machineStr}, bill_duration={$billDur})";
            }
        } elseif (in_array($normalized, ['busy', 'no-answer', 'failed', 'canceled', 'cancelled'], true)) {
            $newStatus = 'failed';
            $errorCode = $normalized;
            $errorMsg  = "Plivo status={$callStatus}" . ($hangupCause ? ", cause={$hangupCause}" : '');
        } else {
            // Intermediate (ringing, in-progress, etc.) — ignore.
            return CallbackResult::ignored();
        }

        // TotalCost is a USD string like "0.01500". Convert to integer cents.
        $costCents = null;
        if ($totalCost !== null && $totalCost !== '') {
            $usd = abs((float) $totalCost);
            if ($usd > 0) {
                $costCents = (int) ceil($usd * 100);
            }
        }

        return new CallbackResult(
            dropId:            (string) $drop->id,
            newStatus:         $newStatus,
            providerCostCents: $costCents,
            errorCode:         $errorCode,
            errorMessage:      $errorMsg,
            raw:               $payload,
        );
    }

    public function healthCheck(): HealthStatus
    {
        $authId = env('PLIVO_AUTH_ID');
        $token  = env('PLIVO_AUTH_TOKEN');

        if (!$authId || !$token) {
            return HealthStatus::down('Plivo platform credentials not configured');
        }

        try {
            $start  = microtime(true);
            $client = new PlivoClient($authId, $token);
            // Lightweight account fetch — same pattern as PlivoService::verifyCredentials.
            $client->accounts->get($authId);
            return HealthStatus::up((int) ((microtime(true) - $start) * 1000));
        } catch (\Throwable $e) {
            return HealthStatus::down('Plivo API: ' . $e->getMessage());
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /**
     * Build a Plivo REST client bound to the tenant's credentials,
     * falling back to the platform env if the tenant has no row in
     * plivo_accounts.
     */
    private function clientFor(int $clientId): PlivoClient
    {
        $account = PlivoAccount::on('master')
            ->where('client_id', $clientId)
            ->first();

        if ($account) {
            [$authId, $token] = $account->resolveCredentials();
        } else {
            $authId = env('PLIVO_AUTH_ID');
            $token  = env('PLIVO_AUTH_TOKEN');
        }

        if (!$authId || !$token) {
            throw new ProviderPermanentError(
                "PlivoProvider: no credentials for client {$clientId}"
            );
        }

        return new PlivoClient($authId, $token);
    }

    /**
     * Build an APP_URL-rooted endpoint with an HMAC signature. The
     * phase-5a.4 route handler recomputes the signature over the same
     * path and rejects mismatches, so arbitrary third parties cannot
     * trigger Plivo XML playback or state mutations by guessing a drop id.
     */
    private function buildSignedUrl(string $path): string
    {
        $base = rtrim((string) (env('RVM_CALLBACK_BASE_URL') ?: env('APP_URL')), '/');
        if ($base === '') {
            throw new ProviderPermanentError(
                'PlivoProvider: RVM_CALLBACK_BASE_URL / APP_URL is not configured'
            );
        }

        $secret = (string) (env('RVM_CALLBACK_SECRET') ?: env('APP_KEY'));
        if ($secret === '') {
            throw new ProviderPermanentError(
                'PlivoProvider: no signing secret (RVM_CALLBACK_SECRET / APP_KEY)'
            );
        }

        $sig = hash_hmac('sha256', $path, $secret);
        return "{$base}/{$path}?sig={$sig}";
    }
}
