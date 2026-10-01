<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\NotFoundException;
use App\Repositories\CompanyRepository;
use App\Repositories\UserRepository;

final class CompanyService
{
    public function __construct(
        private readonly CompanyRepository $companies = new CompanyRepository(),
        private readonly UserRepository $users = new UserRepository(),
    ) {
    }

    public function getCompany(string $companyId): array
    {
        $company = $this->companies->findById($companyId);

        if ($company === null) {
            throw new NotFoundException('Company not found.');
        }

        return $company;
    }

    public function listUsers(string $companyId): array
    {
        return $this->users->listByCompany($companyId);
    }
}
