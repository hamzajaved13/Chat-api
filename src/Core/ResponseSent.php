<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Thrown by Response::send() only when APP_TESTING is defined, so tests
 * can assert on the status code + payload instead of the process exiting.
 * Never thrown/caught in production request handling.
 */
final class ResponseSent extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly array $payload)
    {
        parent::__construct('Response sent (test mode).');
    }
}
