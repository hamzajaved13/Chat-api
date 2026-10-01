<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Exceptions\ValidationException;
use App\Services\AuthService;
use App\Support\Presenter;
use App\Validation\Validator;

final class AuthController
{
    public function __construct(private readonly AuthService $authService = new AuthService())
    {
    }

    public function signup(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email',
            'password' => 'required|password',
            'company_name' => 'required|string|min:2|max:150',
        ])->validate();

        $user = $this->authService->signup($data);

        Response::created(
            [
                'user' => Presenter::user($user),
            ],
            'Signup successful. Please check your email to verify your account before logging in.'
        );
    }

    public function login(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ])->validate();

        $result = $this->authService->login($data['email'], $data['password']);

        Response::success([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'expires_at' => $result['expires_at']->toDateTime()->format(DATE_ATOM),
            'user' => Presenter::user($result['user']),
        ], 'Login successful.');
    }

    public function logout(Request $request): never
    {
        $token = $request->bearerToken();

        if ($token === null) {
            throw new ValidationException(['authorization' => ['Missing bearer token.']]);
        }

        $this->authService->logout($token);

        Response::success([], 'Logout successful. Token has been revoked.');
    }

    public function verifyEmail(Request $request): never
    {
        $token = (string) $request->input('token', '');

        if ($token === '') {
            throw new ValidationException(['token' => ['The token field is required.']]);
        }

        $user = $this->authService->verifyEmail($token);

        Response::success(['user' => Presenter::user($user)], 'Email verified successfully.');
    }

    public function resendVerification(Request $request): never
    {
        $data = Validator::make($request->all(), [
            'email' => 'required|email',
        ])->validate();

        $this->authService->resendVerification($data['email']);

        Response::success([], 'If this email is registered and not yet verified, a new verification email has been sent.');
    }
}
