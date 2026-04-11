<?php

namespace App\Services\Rvm\Exceptions;

/**
 * Thrown by a provider driver on non-retryable failures (invalid number,
 * rejected by carrier, account suspended). The drop is marked failed and
 * the reservation refunded.
 */
class ProviderPermanentError extends RvmException
{
    public function errorCode(): string { return 'rvm.provider_permanent'; }
    public function httpStatus(): int { return 422; }
}
