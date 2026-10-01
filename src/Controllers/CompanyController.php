<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\CompanyService;
use App\Support\Presenter;

final class CompanyController
{
    public function __construct(private readonly CompanyService $companyService = new CompanyService())
    {
    }

    public function show(Request $request): never
    {
        $company = $this->companyService->getCompany((string) $request->user()['company_id']);
        Response::success(['company' => Presenter::company($company)], 'Company retrieved.');
    }

    public function users(Request $request): never
    {
        $users = $this->companyService->listUsers((string) $request->user()['company_id']);
        Response::success(
            ['users' => Presenter::collection($users, [Presenter::class, 'user'])],
            'Company users retrieved.'
        );
    }
}
