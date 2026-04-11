<?php

namespace App\Services\Rvm\Exceptions;

class RateLimitedException extends RvmException
{
    public function __construct(
        public readonly string $dimension,   // "api_key" | "tenant" | "phone" | "provider"
        public readonly int $retryAfterSeconds,
    ) {
        parent::__construct("Rate limit exceeded on '{$dimension}'. Retry in {$retryAfterSeconds}s");
    }

    public function errorCode(): string { return 'rvm.rate_limited'; }
    public function httpStatus(): int { return 429; }
    public function details(): array
    {
        return [
            'dimension' => $this->dimension,
            'retry_after' => $this->retryAfterSeconds,
        ];
    }
}
