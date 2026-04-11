<?php

namespace App\Services\Rvm\Exceptions;

use RuntimeException;

/**
 * Base class for all RVM v2 pipeline errors.
 *
 * Each subclass carries a stable `errorCode` that is returned to API callers
 * as `error.type` and is also the bucket used in metrics / alerting.
 */
abstract class RvmException extends RuntimeException
{
    abstract public function errorCode(): string;

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return [];
    }
}
