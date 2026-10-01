<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Requested resource does not exist (or the caller has no right to know it exists). */
class NotFoundException extends ApiException
{
    protected int $statusCode = 404;

    public function __construct(string $message = 'Resource not found')
    {
        parent::__construct($message);
    }
}
