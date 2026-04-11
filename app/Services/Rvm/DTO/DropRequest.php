<?php

namespace App\Services\Rvm\DTO;

use Illuminate\Http\Request;

/**
 * Validated, normalized drop request.
 *
 * This is the input RvmDropService::createDrop() accepts. Controllers build
 * one of these from the HTTP request and hand it off; services never touch
 * $_REQUEST-shaped data directly.
 */
final class DropRequest
{
    public function __construct(
        public readonly string $phone,
        public readonly string $callerId,
        public readonly int $voiceTemplateId,
        public readonly string $priority,          // Priority::* const
        public readonly ?string $providerHint,     // null = auto
        public readonly ?int $campaignId = null,
        public readonly ?string $scheduledAt = null,
        public readonly bool $respectQuietHours = true,
        public readonly string $timezoneStrategy = 'lead',
        public readonly ?string $callbackUrl = null,
        public readonly array $metadata = [],
    ) {}

    public static function fromRequest(Request $r): self
    {
        return new self(
            phone: (string) $r->input('phone'),
            callerId: (string) $r->input('caller_id'),
            voiceTemplateId: (int) $r->input('voice_template_id'),
            priority: (string) $r->input('priority', Priority::NORMAL),
            providerHint: $r->input('provider') === 'auto' ? null : $r->input('provider'),
            campaignId: $r->input('campaign_id'),
            scheduledAt: $r->input('scheduled_at'),
            respectQuietHours: (bool) $r->input('respect_quiet_hours', true),
            timezoneStrategy: (string) $r->input('timezone_strategy', 'lead'),
            callbackUrl: $r->input('callback_url'),
            metadata: (array) $r->input('metadata', []),
        );
    }
}
