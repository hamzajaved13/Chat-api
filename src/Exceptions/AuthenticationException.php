<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Missing/expired/revoked/malformed token, wrong credentials, unverified email at login, etc. */
class AuthenticationException extends ApiException
{
    protected int $statusCode = 401;

    public function __construct(string $message = 'Unauthenticated')
    {
        parent::__construct($message);
    }
}
