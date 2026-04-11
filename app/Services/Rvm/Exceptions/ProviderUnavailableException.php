<?php

namespace App\Services\Rvm\Exceptions;

class ProviderUnavailableException extends RvmException
{
    public function errorCode(): string { return 'rvm.provider_unavailable'; }
    public function httpStatus(): int { return 503; }
}
