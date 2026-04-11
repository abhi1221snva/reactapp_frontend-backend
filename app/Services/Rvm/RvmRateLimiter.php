<?php

namespace App\Services\Rvm;

use App\Services\Rvm\Exceptions\RateLimitedException;
use Illuminate\Support\Facades\Redis;

/**
 * Four-dimensional token bucket rate limiter.
 *
 * Dimensions enforced:
 *   api_key   — per API key, configurable per-key limit
 *   tenant    — per client, all keys + portal combined
 *   phone     — per destination phone, spam guard (1 drop / 24h default)
 *   provider  — per provider per tenant, matches carrier limits
 *
 * Uses the fixed-window counter pattern via Redis INCR + EXPIRE. Cheap,
 * predictable, good enough for RVM scale.
 */
class RvmRateLimiter
{
    /**
     * Throws RateLimitedException on the first dimension that trips.
     */
    public function check(
        int $clientId,
        ?int $apiKeyId,
        string $phoneE164,
        string $provider,
        int $apiKeyLimitPerMinute = 2000,
        int $tenantLimitPerMinute = 5000,
        int $phoneWindowHours = 24,
        int $providerLimitPerSecond = 30,
    ): void {
        $now = time();

        // Per API key — 60s window
        if ($apiKeyId !== null) {
            $this->hit(
                "rl:apikey:{$apiKeyId}:" . intdiv($now, 60),
                60,
                $apiKeyLimitPerMinute,
                'api_key',
                60 - ($now % 60),
            );
        }

        // Per tenant — 60s window
        $this->hit(
            "rl:tenant:{$clientId}:" . intdiv($now, 60),
            60,
            $tenantLimitPerMinute,
            'tenant',
            60 - ($now % 60),
        );

        // Per destination phone — long window (24h default)
        $windowSeconds = $phoneWindowHours * 3600;
        $phoneKey = "rl:phone:{$clientId}:" . hash('sha256', $phoneE164) . ':' . intdiv($now, $windowSeconds);
        $this->hit($phoneKey, $windowSeconds, 1, 'phone', $windowSeconds);

        // Per provider — 1s window
        $this->hit(
            "rl:provider:{$clientId}:{$provider}:" . $now,
            1,
            $providerLimitPerSecond,
            'provider',
            1,
        );
    }

    private function hit(string $key, int $ttl, int $limit, string $dimension, int $retryAfter): void
    {
        $count = Redis::incr($key);
        if ($count === 1) {
            Redis::expire($key, $ttl);
        }
        if ($count > $limit) {
            throw new RateLimitedException($dimension, $retryAfter);
        }
    }
}
