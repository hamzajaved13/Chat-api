<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Support\Presenter;

final class UserController
{
    public function me(Request $request): never
    {
        Response::success(['user' => Presenter::user($request->user())], 'Authenticated user retrieved.');
    }
}
