<?php

namespace App\Services\Rvm\Exceptions;

class InvalidPhoneException extends RvmException
{
    public function errorCode(): string { return 'rvm.invalid_phone'; }
    public function httpStatus(): int { return 422; }
}
