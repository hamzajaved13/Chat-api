<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Generates cryptographically-secure opaque tokens (auth tokens, email
 * verification tokens). We never store the raw token - only its SHA-256
 * hash - so a leaked database cannot be used to forge sessions.
 */
final class TokenGenerator
{
    public static function generate(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }
}
