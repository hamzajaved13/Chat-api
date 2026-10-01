<?php

declare(strict_types=1);

use App\Config\Env;
use App\Core\Request;
use App\Core\Router;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
Env::load($basePath);

date_default_timezone_set('UTC');

$router = new Router();
require $basePath . '/routes/api.php';

$request = new Request();
$router->dispatch($request);
