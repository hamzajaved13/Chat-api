<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Authenticated, but not allowed to perform this action on this resource. */
class AuthorizationException extends ApiException
{
    protected int $statusCode = 403;

    public function __construct(string $message = 'Forbidden')
    {
        parent::__construct($message);
    }
}
