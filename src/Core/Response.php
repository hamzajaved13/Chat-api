<?php

declare(strict_types=1);

namespace App\Core;


final class Response
{
    public static function success(mixed $data = [], string $message = 'Request successful', int $status = 200): never
    {
        self::send($status, [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ]);
    }

    public static function created(mixed $data = [], string $message = 'Resource created'): never
    {
        self::success($data, $message, 201);
    }

    public static function error(string $message, int $status = 400, array $errors = []): never
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }

        self::send($status, $payload);
    }

    public static function unauthenticated(string $message = 'Unauthenticated'): never
    {
        self::error($message, 401);
    }

    public static function forbidden(string $message = 'Forbidden'): never
    {
        self::error($message, 403);
    }

    public static function notFound(string $message = 'Resource not found'): never
    {
        self::error($message, 404);
    }

    public static function conflict(string $message = 'Conflict'): never
    {
        self::error($message, 409);
    }

    public static function validationFailed(array $errors, string $message = 'Validation failed'): never
    {
        self::error($message, 422, $errors);
    }

    private static function send(int $status, array $payload): never
    {

        if (defined('APP_TESTING') && APP_TESTING === true) {
            throw new \App\Core\ResponseSent($status, $payload);
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json');
        }

        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
