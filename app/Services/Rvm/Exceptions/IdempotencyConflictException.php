<?php

namespace App\Services\Rvm\Exceptions;

class IdempotencyConflictException extends RvmException
{
    public function errorCode(): string { return 'rvm.idempotency_conflict'; }
    public function httpStatus(): int { return 409; }
}
