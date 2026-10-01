<?php

declare(strict_types=1);

namespace App\Exceptions;

/** Thrown by the Validator when incoming request data fails validation rules. */
class ValidationException extends ApiException
{
    protected int $statusCode = 422;

    /** @var array<string, array<int, string>> */
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
