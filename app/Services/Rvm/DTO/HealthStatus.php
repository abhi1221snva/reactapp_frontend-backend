<?php

namespace App\Services\Rvm\DTO;

final class HealthStatus
{
    public function __construct(
        public readonly bool $healthy,
        public readonly ?string $message = null,
        public readonly ?int $latencyMs = null,
    ) {}

    public static function up(?int $latencyMs = null): self
    {
        return new self(true, null, $latencyMs);
    }

    public static function down(string $message): self
    {
        return new self(false, $message, null);
    }
}
