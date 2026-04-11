<?php

namespace App\Services\Rvm\Exceptions;

class QuietHoursException extends RvmException
{
    public function errorCode(): string { return 'rvm.quiet_hours'; }
    public function httpStatus(): int { return 409; }
}
