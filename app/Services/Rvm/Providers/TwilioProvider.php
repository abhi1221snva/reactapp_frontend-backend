<?php

namespace App\Services\Rvm\Providers;

use App\Model\Master\Rvm\Drop;
use App\Model\TwilioAccount;
use App\Services\Rvm\DTO\CallbackResult;
use App\Services\Rvm\DTO\DeliveryResult;
use App\Services\Rvm\DTO\HealthStatus;
use App\Services\Rvm\Exceptions\ProviderPermanentError;
use App\Services\Rvm\Exceptions\ProviderTransientError;
use Illuminate\Support\Facades\Log;
use Twilio\Exceptions\RestException;
use Twilio\Exceptions\TwilioException;
use Twilio\Rest\Client as TwilioClient;

/**
 * Twilio RVM driver.
 *
 * Delivery model:
 *   1. Originate an outbound call via the Twilio REST API with
 *      machineDetection=DetectMessageEnd. Twilio rings the destination,
 *      waits through the answering-machine greeting, detects the beep,
 *      and THEN fetches our TwiML URL.
 *   2. Our TwiML endpoint returns a <Play> verb streaming the voicemail
 *      audio tied to the drop's voice_template_id.
 *   3. Twilio POSTs a statusCallback when the call completes — we use
 *      that to resolve delivered/failed state in handleCallback().
 *
 * Because the outcome only arrives via callback, deliver() returns
 * DeliveryResult::dispatching() — ProcessRvmDropJob keeps the wallet
 * reservation open until the callback flips the drop to delivered.
 *
 * Credentials are resolved per-tenant directly from the twilio_accounts
 * master table (same AES-decrypted pipeline used by TwilioService::forClient).
 * We build a fresh Twilio\Rest\Client rather than going through
 * TwilioService::makeCall so the RVM call flow isn't mixed with the
 * two-party-call defaults (recording, generic webhook URLs) that suit
 * the existing inbound/outbound call paths.
 */
class TwilioProvider implements RvmProviderInterface
{
    private const DRIVER_NAME = 'twilio';

    /** Seconds Twilio is allowed to spend detecting the answering machine. */
    private const AMD_TIMEOUT_SECONDS = 30;

    /** Total ring timeout before Twilio gives up on the call. */
    private const CALL_TIMEOUT_SECONDS = 60;

    /**
     * Twilio REST error codes treated as non-retryable.
     *
     * These come from https://www.twilio.com/docs/api/errors — all are
     * caller-fault conditions (invalid/unverified number, account
     * suspended, blocked geography). Retrying won't change the outcome.
     */
    private const PERMANENT_ERROR_CODES = [
        13223, // Invalid To/From phone number for forwarding
        13224, // Dial: phone number is not valid
        13225, // Dial: forbidden phone number
        13226, // Dial: invalid country code
        13227, // Geographic permission error (country not enabled)
        20003, // Authentication failed
        21201, // No 'To' number provided
        21210, // 'From' phone number not Twilio-owned
        21211, // Invalid 'To' phone number
        21214, // 'To' phone number cannot be reached
        21215, // Geo permissions for the 'To' number disabled
        21217, // Phone number does not appear to be valid
        21219, // 'To' phone number is not verified
        21608, // The 'From' number is unverified (trial account)
        21610, // Attempt to send to unsubscribed recipient
        21611, // This 'From' number has exceeded rate limit
    ];

    public function name(): string
    {
        return self::DRIVER_NAME;
    }

    public function supports(Drop $drop): bool
    {
        // Twilio supports any E.164 destination the account is permitted
        // to reach. Country-level restrictions live on the account itself
        // and surface as REST error 13227, which we map to permanent
        // failure in deliver(). Here we just sanity-check the phone shape.
        return (bool) preg_match('/^\+\d{8,15}$/', (string) $drop->phone_e164);
    }

    public function estimateCost(Drop $drop): int
    {
        // Twilio outbound voice averages ~1.5¢/min + ~0.75¢ AMD surcharge.
        // A typical voicemail drop runs 10–20s so 4¢ is a conservative
        // reserve — the wallet refunds any unused amount once the
        // statusCallback reports the final Twilio Price field.
        $override = env('RVM_TWILIO_COST_CENTS');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }
        return (int) config('rvm.default_cost_cents', 2) + 2;
    }

    public function deliver(Drop $drop): DeliveryResult
    {
        if (!$drop->client_id) {
            throw new ProviderPermanentError('TwilioProvider: drop has no client_id');
        }
        if (!$drop->caller_id) {
            throw new ProviderPermanentError('TwilioProvider: drop has no caller_id');
        }
        if (!$drop->phone_e164) {
            throw new ProviderPermanentError('TwilioProvider: drop has no phone_e164');
        }

        $client = $this->clientFor((int) $drop->client_id);

        $twimlUrl  = $this->buildSignedUrl('rvm/twilio/twiml/'  . $drop->id);
        $statusUrl = $this->buildSignedUrl('rvm/twilio/status/' . $drop->id);

        try {
            $call = $client->calls->create(
                $drop->phone_e164,   // To
                $drop->caller_id,    // From
                [
                    'url'                     => $twimlUrl,
                    'method'                  => 'POST',
                    'statusCallback'          => $statusUrl,
                    'statusCallbackMethod'    => 'POST',
                    // 'completed' is all we need for final state resolution;
                    // intermediate events would just be noise for an RVM drop.
                    'statusCallbackEvent'     => ['completed'],
                    'machineDetection'        => 'DetectMessageEnd',
                    'machineDetectionTimeout' => self::AMD_TIMEOUT_SECONDS,
                    'timeout'                 => self::CALL_TIMEOUT_SECONDS,
                    // Never record a voicemail drop — compliance + privacy.
                    'record'                  => false,
                ]
            );
        } catch (RestException $e) {
            $code = (int) $e->getCode();

            if (in_array($code, self::PERMANENT_ERROR_CODES, true)) {
                throw new ProviderPermanentError(
                    "TwilioProvider: {$e->getMessage()} (code {$code})"
                );
            }

            // Everything else (5xx, 429, unknown) is transient.
            throw new ProviderTransientError(
                "TwilioProvider: REST error {$code}: {$e->getMessage()}"
            );
        } catch (TwilioException $e) {
            // Non-REST SDK exceptions are almost always transport-level
            // (DNS, TLS, TCP reset) — safe to retry.
            throw new ProviderTransientError('TwilioProvider: ' . $e->getMessage());
        }

        return DeliveryResult::dispatching(
            externalId: (string) $call->sid,
            costCents: $this->estimateCost($drop),
            raw: [
                'call_sid' => (string) $call->sid,
                'status'   => (string) $call->status,
                'to'       => (string) $call->to,
                'from'     => (string) $call->from,
            ],
        );
    }

    public function handleCallback(array $payload, array $headers): CallbackResult
    {
        // The route handler (Phase 5a.4) is responsible for verifying the
        // Twilio X-Twilio-Signature header + the `?sig=` HMAC before
        // dispatching into this method. We trust the payload here.
        $callSid    = $payload['CallSid']    ?? null;
        $callStatus = $payload['CallStatus'] ?? null;
        $answeredBy = $payload['AnsweredBy'] ?? null;
        $priceStr   = $payload['Price']      ?? null;

        if (!$callSid || !$callStatus) {
            return CallbackResult::ignored();
        }

        // Resolve the drop by provider_message_id — which ProcessRvmDropJob
        // set to the CallSid when it persisted the dispatching result.
        $drop = Drop::on('master')
            ->where('provider', self::DRIVER_NAME)
            ->where('provider_message_id', (string) $callSid)
            ->first();

        if (!$drop) {
            Log::warning('TwilioProvider: callback for unknown drop', [
                'call_sid' => $callSid,
                'status'   => $callStatus,
            ]);
            return CallbackResult::ignored();
        }

        // Map Twilio terminal states → drop statuses. We only count the
        // drop as "delivered" when the answer was detected as a machine.
        // A human answer means we couldn't leave the voicemail, so we
        // class it as failed (the wallet is refunded; tenant is not
        // charged for a call that went to a live person).
        $isMachine = is_string($answeredBy)
            && str_starts_with($answeredBy, 'machine');

        $newStatus = match ($callStatus) {
            'completed'                                => $isMachine ? 'delivered' : 'failed',
            'busy', 'no-answer', 'failed', 'canceled'  => 'failed',
            default                                    => null,
        };

        if ($newStatus === null) {
            // Intermediate state (queued, ringing, in-progress) — no action.
            return CallbackResult::ignored();
        }

        // Twilio reports price as a negative USD string ("-0.0150").
        // Convert to a positive integer number of cents, rounding up so
        // we never under-charge. Missing/empty → null (keep estimate).
        $costCents = null;
        if ($priceStr !== null && $priceStr !== '') {
            $usd = abs((float) $priceStr);
            if ($usd > 0) {
                $costCents = (int) ceil($usd * 100);
            }
        }

        $errorCode = null;
        $errorMsg  = null;
        if ($newStatus === 'failed') {
            $errorCode = $callStatus === 'completed'
                ? ('human_answer:' . ($answeredBy ?? 'unknown'))
                : $callStatus;
            $errorMsg  = "Twilio call_status={$callStatus}, answered_by=" . ($answeredBy ?? 'null');
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
        // Platform-level health: we use the master-account credentials so
        // we're not coupled to any specific tenant being healthy. This
        // matches how AsteriskProvider probes — it checks "can we talk to
        // the infra at all", not "is any individual tenant misconfigured".
        $sid   = env('TWILIO_SID');
        $token = env('TWILIO_AUTH_TOKEN');

        if (!$sid || !$token) {
            return HealthStatus::down('Twilio platform credentials not configured');
        }

        try {
            $start  = microtime(true);
            $client = new TwilioClient($sid, $token);
            $client->api->v2010->accounts($sid)->fetch();
            return HealthStatus::up((int) ((microtime(true) - $start) * 1000));
        } catch (\Throwable $e) {
            return HealthStatus::down('Twilio API: ' . $e->getMessage());
        }
    }

    // ── Internals ──────────────────────────────────────────────────────────

    /**
     * Build a Twilio REST client bound to the tenant's credentials,
     * falling back to the platform master account if the tenant has no
     * row in twilio_accounts.
     */
    private function clientFor(int $clientId): TwilioClient
    {
        $account = TwilioAccount::on('master')
            ->where('client_id', $clientId)
            ->first();

        if ($account) {
            [$sid, $token] = $account->resolveCredentials();
        } else {
            $sid   = env('TWILIO_SID');
            $token = env('TWILIO_AUTH_TOKEN');
        }

        if (!$sid || !$token) {
            throw new ProviderPermanentError(
                "TwilioProvider: no credentials for client {$clientId}"
            );
        }

        return new TwilioClient($sid, $token);
    }

    /**
     * Build an APP_URL-rooted endpoint with an HMAC signature so that
     * arbitrary third parties cannot trigger TwiML playback or state
     * mutations by guessing a drop id. The phase-5a.4 route handler
     * recomputes the signature over the same path and rejects mismatches.
     */
    private function buildSignedUrl(string $path): string
    {
        $base = rtrim((string) (env('RVM_CALLBACK_BASE_URL') ?: env('APP_URL')), '/');
        if ($base === '') {
            throw new ProviderPermanentError(
                'TwilioProvider: RVM_CALLBACK_BASE_URL / APP_URL is not configured'
            );
        }

        $secret = (string) (env('RVM_CALLBACK_SECRET') ?: env('APP_KEY'));
        if ($secret === '') {
            throw new ProviderPermanentError(
                'TwilioProvider: no signing secret (RVM_CALLBACK_SECRET / APP_KEY)'
            );
        }

        $sig = hash_hmac('sha256', $path, $secret);
        return "{$base}/{$path}?sig={$sig}";
    }
}
