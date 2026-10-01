<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Duplicate email, duplicate membership, already-verified account, etc. */
class ConflictException extends ApiException
{
    protected int $statusCode = 409;

    public function __construct(string $message = 'Conflict')
    {
        parent::__construct($message);
    }
}
