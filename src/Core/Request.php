<?php

declare(strict_types=1);

namespace App\Core;


final class Request
{
    private array $query;
    private array $body;
    private array $headers;
    private array $params = [];
    private string $method;
    private string $path;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path = rtrim((string) parse_url($uri, PHP_URL_PATH), '/');
        if ($this->path === '') {
            $this->path = '/';
        }

        $this->query = $_GET ?? [];
        $this->headers = $this->extractHeaders();
        $this->body = $this->parseBody();
    }

    private function extractHeaders(): array
    {
        $headers = [];

        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
            return $headers;
        }

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    private function parseBody(): array
    {
        $contentType = $this->headers['content-type'] ?? '';


        if (str_contains($contentType, 'multipart/form-data')) {
            return $_POST ?? [];
        }

        $raw = file_get_contents('php://input') ?: '';

        if ($raw === '') {
            return [];
        }


        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            parse_str($raw, $parsed);
            return $parsed;
        }

        // Default / application/json / no content-type set: try JSON first.
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Fall back to urlencoded parsing for anything else recognizable.
        parse_str($raw, $parsed);
        return $parsed;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('authorization', '');

        if (is_string($header) && preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /** Authenticated user, set by AuthMiddleware. Kept here for convenience. */
    private ?array $user = null;

    public function setUser(array $user): void
    {
        $this->user = $user;
    }

    public function user(): ?array
    {
        return $this->user;
    }
}
