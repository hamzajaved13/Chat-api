<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ApiException;
use App\Exceptions\AuthenticationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use Throwable;


final class Router
{
    
    private array $routes = [];

    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, callable $handler, array $middleware): void
    {
        $paramNames = [];
        $pattern = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'paramNames' => $paramNames,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        $pathMatchedForOtherMethod = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            if ($route['method'] !== $method) {
                $pathMatchedForOtherMethod = true;
                continue;
            }

            array_shift($matches);
            $params = array_combine($route['paramNames'], $matches);
            $request->setParams($params ?: []);

            try {
                $this->runMiddlewareChain($route['middleware'], $request, $route['handler']);
            } catch (ResponseSent $e) {
                // Test-mode only (see Response::send) - let it propagate to the caller.
                throw $e;
            } catch (ValidationException $e) {
                Response::validationFailed($e->errors(), $e->getMessage());
            } catch (AuthenticationException $e) {
                Response::unauthenticated($e->getMessage());
            } catch (AuthorizationException $e) {
                Response::forbidden($e->getMessage());
            } catch (NotFoundException $e) {
                Response::notFound($e->getMessage());
            } catch (ApiException $e) {
                Response::error($e->getMessage(), $e->statusCode());
            } catch (Throwable $e) {
                $debug = \App\Config\Env::get('APP_DEBUG', false);
                Response::error(
                    $debug ? ('Internal error: ' . $e->getMessage()) : 'Internal server error',
                    500,
                    $debug ? ['exception' => [$e->getFile() . ':' . $e->getLine()]] : []
                );
            }

            return;
        }

        if ($pathMatchedForOtherMethod) {
            Response::error('Method not allowed', 405);
        }

        Response::notFound('Route not found');
    }

    private function runMiddlewareChain(array $middleware, Request $request, callable $finalHandler): void
    {
        $next = $finalHandler;

        foreach (array_reverse($middleware) as $mw) {
            $next = function (Request $req) use ($mw, $next) {
                return $mw->handle($req, $next);
            };
        }

        $next($request);
    }
}
