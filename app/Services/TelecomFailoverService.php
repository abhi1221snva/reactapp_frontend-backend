<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class TelecomFailoverService
{
    const PROVIDER_TWILIO  = 'twilio';
    const PROVIDER_PLIVO   = 'plivo';

    // After this many consecutive failures, mark provider as degraded
    const FAILURE_THRESHOLD = 3;
    // How long to keep provider in degraded state before retrying (seconds)
    const DEGRADED_TTL      = 300; // 5 minutes

    // ─── Instance-based API (new) ────────────────────────────────────────────────

    private int $clientId;

    public function __construct(int $clientId)
    {
        $this->clientId = $clientId;
    }

    public static function forClient(int $clientId): self
    {
        return new self($clientId);
    }

    /**
     * Execute a callable against the active provider, retrying with the other
     * provider on failure (automatic failover).
     *
     * Usage:
     *   TelecomFailoverService::forClient($id)->execute(function($service) use ($to, $from) {
     *       return $service->makeCall($to, $from, $url);
     *   });
     *
     * @param callable $callback  receives (TwilioService|PlivoService $service)
     */
    public function execute(callable $callback, int $maxRetries = 2): mixed
    {
        $provider  = self::getActiveProvider($this->clientId);
        $lastError = null;

        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $service = $provider === self::PROVIDER_TWILIO
                    ? TwilioService::forClient($this->clientId)
                    : PlivoService::forClient($this->clientId);

                $result = $callback($service);
                self::recordSuccess($this->clientId, $provider);
                return $result;
            } catch (\Exception $e) {
                $lastError = $e;
                $provider  = self::recordFailure($this->clientId, $provider);
                Log::warning('[TelecomFailover] execute() retry with ' . $provider, [
                    'client' => $this->clientId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? new \RuntimeException('Telecom call failed after all retries');
    }

    /**
     * Get combined provider health status (instance-based wrapper).
     */
    public function getStatus(): array
    {
        $health = self::getProviderHealth($this->clientId);
        return [
            'active_provider' => self::getActiveProvider($this->clientId),
            'providers'       => $health,
        ];
    }

    /**
     * Manually set the active provider (admin override).
     */
    public function setProvider(string $provider): void
    {
        if (!in_array($provider, [self::PROVIDER_TWILIO, self::PROVIDER_PLIVO], true)) {
            throw new \InvalidArgumentException("Unknown provider: {$provider}");
        }

        // Clear the specified provider from degraded list so it becomes active
        $degradedKey = "telecom:degraded:{$this->clientId}";
        $degraded    = Cache::get($degradedKey, []);

        // Reset the other provider to degraded to force the chosen one active
        $other   = $provider === self::PROVIDER_TWILIO ? self::PROVIDER_PLIVO : self::PROVIDER_TWILIO;
        $degraded = [$other]; // mark other as degraded so chosen provider wins

        Cache::put($degradedKey, $degraded, self::DEGRADED_TTL);
        Cache::forget("telecom:failures:{$this->clientId}:{$provider}");

        Log::info("[TelecomFailover] Manual provider override → {$provider}", ['client' => $this->clientId]);
    }

    /**
     * Reset failure counters for a provider.
     */
    public function resetStats(string $provider): void
    {
        Cache::forget("telecom:failures:{$this->clientId}:{$provider}");
        self::recordSuccess($this->clientId, $provider);
        Log::info("[TelecomFailover] Stats reset for {$provider}", ['client' => $this->clientId]);
    }

    /**
     * Get the active provider for a client, respecting failover state.
     */
    public static function getActiveProvider(int $clientId): string
    {
        $degradedKey = "telecom:degraded:{$clientId}";
        $degraded    = Cache::get($degradedKey, []);

        // Try primary provider first (Twilio)
        if (!in_array(self::PROVIDER_TWILIO, $degraded)) {
            return self::PROVIDER_TWILIO;
        }

        // Primary degraded -- try Plivo
        if (!in_array(self::PROVIDER_PLIVO, $degraded)) {
            Log::warning('[TelecomFailover] Twilio degraded -- routing via Plivo', [
                'client_id' => $clientId,
            ]);
            return self::PROVIDER_PLIVO;
        }

        // Both degraded -- fall back to primary and hope for recovery
        Log::error('[TelecomFailover] All providers degraded -- forcing Twilio', [
            'client_id' => $clientId,
        ]);
        return self::PROVIDER_TWILIO;
    }

    /**
     * Record a successful call for a provider (resets failure counter).
     */
    public static function recordSuccess(int $clientId, string $provider): void
    {
        $failKey = "telecom:failures:{$clientId}:{$provider}";
        Cache::forget($failKey);

        // Remove from degraded list on success
        $degradedKey = "telecom:degraded:{$clientId}";
        $degraded    = Cache::get($degradedKey, []);
        $degraded    = array_values(array_diff($degraded, [$provider]));
        Cache::put($degradedKey, $degraded, self::DEGRADED_TTL);
    }

    /**
     * Record a failed call attempt for a provider.
     * Returns the provider to use for retry (may trigger failover).
     */
    public static function recordFailure(int $clientId, string $provider): string
    {
        $failKey  = "telecom:failures:{$clientId}:{$provider}";
        $failures = (int) Cache::get($failKey, 0) + 1;
        Cache::put($failKey, $failures, self::DEGRADED_TTL);

        Log::warning('[TelecomFailover] Provider failure recorded', [
            'client_id'  => $clientId,
            'provider'   => $provider,
            'failures'   => $failures,
            'threshold'  => self::FAILURE_THRESHOLD,
        ]);

        if ($failures >= self::FAILURE_THRESHOLD) {
            // Mark provider as degraded
            $degradedKey = "telecom:degraded:{$clientId}";
            $degraded    = Cache::get($degradedKey, []);
            if (!in_array($provider, $degraded)) {
                $degraded[] = $provider;
            }
            Cache::put($degradedKey, $degraded, self::DEGRADED_TTL);

            Log::error('[TelecomFailover] Provider marked degraded -- initiating failover', [
                'client_id'  => $clientId,
                'provider'   => $provider,
            ]);
        }

        // Return the new active provider
        return self::getActiveProvider($clientId);
    }

    /**
     * Make a call with automatic failover.
     * Tries primary, falls back to secondary on failure.
     *
     * TwilioService::makeCall(string $to, string $from, string $twimlUrl, array $opts = [])
     * PlivoService::makeCall(string $to, string $from, array $opts = [])
     *
     * @param int    $clientId
     * @param string $from
     * @param string $to
     * @param array  $options  Additional options passed to provider (url, answer_url, etc.)
     * @return array  ['provider' => string, 'call_id' => string|null, 'success' => bool]
     */
    public static function makeCallWithFailover(int $clientId, string $from, string $to, array $options = []): array
    {
        $provider    = self::getActiveProvider($clientId);
        $attempts    = 0;
        $maxAttempts = 2;

        while ($attempts < $maxAttempts) {
            $attempts++;
            try {
                if ($provider === self::PROVIDER_TWILIO) {
                    $twimlUrl = $options['url'] ?? (env('APP_URL') . '/twilio/webhook/inbound-call');
                    $service  = TwilioService::forClient($clientId);
                    $result   = $service->makeCall($to, $from, $twimlUrl);
                    self::recordSuccess($clientId, self::PROVIDER_TWILIO);
                    return [
                        'provider' => self::PROVIDER_TWILIO,
                        'call_id'  => $result['call_sid'] ?? null,
                        'success'  => true,
                    ];
                } else {
                    $service = PlivoService::forClient($clientId);
                    $result  = $service->makeCall($to, $from, $options);
                    self::recordSuccess($clientId, self::PROVIDER_PLIVO);
                    return [
                        'provider' => self::PROVIDER_PLIVO,
                        'call_id'  => $result['call_uuid'] ?? null,
                        'success'  => true,
                    ];
                }
            } catch (\Exception $e) {
                Log::error('[TelecomFailover] Call attempt failed', [
                    'client_id' => $clientId,
                    'provider'  => $provider,
                    'attempt'   => $attempts,
                    'error'     => $e->getMessage(),
                ]);
                $provider = self::recordFailure($clientId, $provider);
            }
        }

        return ['provider' => $provider, 'call_id' => null, 'success' => false];
    }

    /**
     * Get current provider health status for a client.
     */
    public static function getProviderHealth(int $clientId): array
    {
        $degradedKey = "telecom:degraded:{$clientId}";
        $degraded    = Cache::get($degradedKey, []);

        return [
            self::PROVIDER_TWILIO => [
                'status'   => in_array(self::PROVIDER_TWILIO, $degraded) ? 'degraded' : 'healthy',
                'failures' => (int) Cache::get("telecom:failures:{$clientId}:" . self::PROVIDER_TWILIO, 0),
            ],
            self::PROVIDER_PLIVO => [
                'status'   => in_array(self::PROVIDER_PLIVO, $degraded) ? 'degraded' : 'healthy',
                'failures' => (int) Cache::get("telecom:failures:{$clientId}:" . self::PROVIDER_PLIVO, 0),
            ],
        ];
    }
}
