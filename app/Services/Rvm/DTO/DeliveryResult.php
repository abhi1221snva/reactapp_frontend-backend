<?php

namespace App\Services\Rvm\DTO;

/**
 * Immutable return value from RvmProviderInterface::deliver().
 *
 * status semantics:
 *   "delivered"    — provider confirmed terminal success (sync drop)
 *   "dispatching"  — provider accepted the request; final outcome arrives
 *                    asynchronously via handleCallback()
 *   "failed"       — provider rejected the drop (permanent; no retry)
 */
final class DeliveryResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $externalId = null,
        public readonly ?int $costCents = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}

    public static function delivered(?string $externalId, ?int $costCents = null, array $raw = []): self
    {
        return new self('delivered', $externalId, $costCents, null, null, $raw);
    }

    public static function dispatching(string $externalId, ?int $costCents = null, array $raw = []): self
    {
        return new self('dispatching', $externalId, $costCents, null, null, $raw);
    }

    public static function failed(string $errorCode, string $errorMessage, array $raw = []): self
    {
        return new self('failed', null, null, $errorCode, $errorMessage, $raw);
    }

    public function successful(): bool
    {
        return $this->status !== 'failed';
    }
}
