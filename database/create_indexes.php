<?php

declare(strict_types=1);

/**
 * Run once (or any time after a fresh `mongo` deployment/reset) to create
 * every index required by the spec (section 14):
 *
 *   php database/create_indexes.php
 */

use App\Config\Env;
use App\Repositories\AuthTokenRepository;
use App\Repositories\ChannelRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\EmailVerificationTokenRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);
Env::load($basePath);

$repositories = [
    'users' => new UserRepository(),
    'companies' => new CompanyRepository(),
    'teams / team_members' => new TeamRepository(),
    'channels / channel_members' => new ChannelRepository(),
    'auth_tokens' => new AuthTokenRepository(),
    'email_verification_tokens' => new EmailVerificationTokenRepository(),
];

foreach ($repositories as $label => $repository) {
    $repository->ensureIndexes();
    echo "Indexes ensured for: {$label}\n";
}

// messages.channel_id + created_at is created via MessageRepository.
(new \App\Repositories\MessageRepository())->ensureIndexes();
echo "Indexes ensured for: messages\n";

echo "\nAll indexes created successfully.\n";
