<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Exceptions\AuthenticationException;
use App\Services\AuthService;

/**
 * Requires a valid `Authorization: Bearer <token>` header, resolves the
 * token to its owning user, and attaches it to the request via
 * Request::setUser() so downstream controllers/services don't have to
 * re-resolve it.
 */
final class AuthMiddleware
{
    public function __construct(private readonly AuthService $authService = new AuthService())
    {
    }

    public function handle(Request $request, callable $next): mixed
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            throw new AuthenticationException('Missing or malformed Authorization header. Expected: Bearer <token>.');
        }

        $user = $this->authService->userFromToken($token);
        $request->setUser($user);

        return $next($request);
    }
}
