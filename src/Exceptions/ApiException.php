<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Base exception for all handled application errors. The Router catches
 * this (and its subclasses) and converts it into the standard JSON error
 * envelope instead of leaking a stack trace.
 */
class ApiException extends RuntimeException
{
    protected int $statusCode = 400;

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
