<?php

namespace App\Services\Rvm\DTO;

/**
 * Result of processing an inbound provider callback (Twilio/Plivo/etc.).
 *
 * dropId    — the resolved drop this callback belongs to (via provider_message_id)
 * newStatus — the status to apply to the drop, if any
 */
final class CallbackResult
{
    public function __construct(
        public readonly ?string $dropId,
        public readonly ?string $newStatus,
        public readonly ?int $providerCostCents = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}

    public static function ignored(): self
    {
        return new self(null, null);
    }
}
