<?php

declare(strict_types=1);

/**
 * Dependency-free smoke test for the parts of the framework that don't
 * require a live MongoDB connection: routing/dispatch and validation.
 * Run with: php tests/smoke_test.php
 */

if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
} else {
    // Minimal PSR-4 fallback so this smoke test can run before `composer
    // install` (e.g. in CI sandboxes without registry access). This is
    // NOT used by the actual application - use `composer install`.
    spl_autoload_register(function (string $class) {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

use App\Core\Router;
use App\Core\Request;
use App\Core\ResponseSent;
use App\Validation\Validator;
use App\Exceptions\ValidationException;

define('APP_TESTING', true);

$failures = 0;

function check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? "PASS" : "FAIL") . " - {$label}\n";
    if (!$condition) {
        $failures++;
    }
}

// --- Router param extraction -------------------------------------------
$router = new Router();
$captured = null;
$router->get('/api/teams/{teamId}/channels/{channelId}', function (Request $req) use (&$captured) {
    $captured = [$req->param('teamId'), $req->param('channelId')];
    echo json_encode(['ok' => true]);
});

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/teams/abc123/channels/def456';
$router->dispatch(new Request());
check('Router extracts multiple route params', $captured === ['abc123', 'def456']);

// --- Router 404 -----------------------------------------------------
$router2 = new Router();
$router2->get('/api/only', fn () => null);
$_SERVER['REQUEST_URI'] = '/api/nope';
try {
    $router2->dispatch(new Request());
    check('Router returns 404 JSON for unknown route', false);
} catch (ResponseSent $e) {
    check(
        'Router returns 404 JSON for unknown route',
        $e->status === 404 && $e->payload['success'] === false && $e->payload['message'] === 'Route not found'
    );
}

// --- Router 405 -------------------------------------------------------
$router3 = new Router();
$router3->post('/api/only-post', fn () => null);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/api/only-post';
try {
    $router3->dispatch(new Request());
    check('Router returns 405 for wrong method on known path', false);
} catch (ResponseSent $e) {
    check('Router returns 405 for wrong method on known path', $e->status === 405);
}

// --- Validator required/email/password rules --------------------------
try {
    Validator::make([], ['email' => 'required|email'])->validate();
    check('Validator throws on missing required field', false);
} catch (ValidationException $e) {
    check('Validator throws on missing required field', isset($e->errors()['email']));
}

try {
    Validator::make(['email' => 'not-an-email'], ['email' => 'required|email'])->validate();
    check('Validator throws on invalid email', false);
} catch (ValidationException $e) {
    check('Validator throws on invalid email', isset($e->errors()['email']));
}

try {
    Validator::make(['password' => 'weak'], ['password' => 'required|password'])->validate();
    check('Validator throws on weak password', false);
} catch (ValidationException $e) {
    check('Validator throws on weak password', isset($e->errors()['password']));
}

$valid = Validator::make(
    ['name' => 'Jane', 'email' => 'jane@example.com', 'password' => 'secret123'],
    ['name' => 'required|string', 'email' => 'required|email', 'password' => 'required|password']
)->validate();
check('Validator returns validated data on success', $valid['email'] === 'jane@example.com');

echo "\n" . ($failures === 0 ? "All smoke tests passed." : "{$failures} smoke test(s) failed.") . "\n";
exit($failures === 0 ? 0 : 1);
