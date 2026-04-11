<?php

namespace App\Services\Rvm\Exceptions;

class DncBlockedException extends RvmException
{
    public function errorCode(): string { return 'rvm.dnc_blocked'; }
    public function httpStatus(): int { return 403; }
}
