<?php

namespace App\Services\Rvm;

use App\Model\Master\Rvm\Drop;
use App\Services\Rvm\Exceptions\IdempotencyConflictException;
use Illuminate\Support\Facades\Redis;

/**
 * Redis-backed idempotency store.
 *
 * Key:   idem:rvm:{client_id}:{key}
 * Value: JSON { drop_id, request_fingerprint }
 * TTL:   24 hours
 *
 * Two behaviours:
 *   - Same key + same request fingerprint → return cached result (replay)
 *   - Same key + different fingerprint    → throw IdempotencyConflictException
 *
 * The unique index on rvm_drops(client_id, idempotency_key) is the final
 * safety net if two workers race past the Redis check.
 */
class RvmIdempotencyStore
{
    private const TTL_SECONDS = 86400;

    /**
     * Look up a previous drop for this idempotency key.
     * Returns null if no match. Throws on fingerprint mismatch.
     */
    public function lookup(int $clientId, ?string $key, string $fingerprint): ?Drop
    {
        if (!$key) return null;

        $cached = Redis::get($this->redisKey($clientId, $key));
        if (!$cached) return null;

        $row = json_decode($cached, true);
        if (!is_array($row) || empty($row['drop_id'])) return null;

        if (($row['fingerprint'] ?? null) !== $fingerprint) {
            throw new IdempotencyConflictException(
                "Idempotency-Key '{$key}' was previously used with a different request body"
            );
        }

        return Drop::on('master')->find($row['drop_id']);
    }

    /**
     * Record a new idempotent result.
     */
    public function remember(int $clientId, ?string $key, string $fingerprint, Drop $drop): void
    {
        if (!$key) return;

        Redis::setex(
            $this->redisKey($clientId, $key),
            self::TTL_SECONDS,
            json_encode([
                'drop_id' => $drop->id,
                'fingerprint' => $fingerprint,
            ], JSON_THROW_ON_ERROR)
        );
    }

    /**
     * Stable fingerprint of the request body so replays can be detected.
     */
    public static function fingerprint(array $body): string
    {
        ksort($body);
        return hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function redisKey(int $clientId, string $key): string
    {
        return "idem:rvm:{$clientId}:" . hash('sha256', $key);
    }
}
