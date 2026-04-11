<?php

namespace App\Services\Rvm\Exceptions;

/**
 * Thrown by a provider driver when the failure is retryable (network, 5xx,
 * temporary rate limit). The job worker will catch and requeue with backoff.
 */
class ProviderTransientError extends RvmException
{
    public function errorCode(): string { return 'rvm.provider_transient'; }
    public function httpStatus(): int { return 503; }
}
