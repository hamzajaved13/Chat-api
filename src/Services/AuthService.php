<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Exceptions\AuthenticationException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Repositories\AuthTokenRepository;
use App\Repositories\ChannelRepository;
use App\Repositories\CompanyRepository;
use App\Repositories\EmailVerificationTokenRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Support\MailService;
use App\Support\Security;
use App\Support\TokenGenerator;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Throwable;

final class AuthService
{
    private const STATUS_PENDING = 'email_verification_pending';
    private const STATUS_ACTIVE = 'active';

    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly CompanyRepository $companies = new CompanyRepository(),
        private readonly TeamRepository $teams = new TeamRepository(),
        private readonly ChannelRepository $channels = new ChannelRepository(),
        private readonly AuthTokenRepository $authTokens = new AuthTokenRepository(),
        private readonly EmailVerificationTokenRepository $verificationTokens = new EmailVerificationTokenRepository(),
    ) {
    }


    public function signup(array $data): array
    {
        $email = strtolower(trim($data['email']));

        if ($this->users->findByEmail($email) !== null) {
            throw new ConflictException('An account with this email already exists.');
        }

        $result = $this->attemptTransactionalSignup($data, $email);

        if ($result === null) {

            $created = [];
            try {
                $result = $this->createUserCompanyTeamChannelNoSession($data, $email, $created);
            } catch (Throwable $e) {
                foreach (array_reverse($created) as [$collection, $id]) {
                    try {
                        Database::collection($collection)->deleteOne(['_id' => $id]);
                    } catch (Throwable) {

                    }
                }
                throw $e;
            }
        }

        [$user, $verificationRawToken] = [$result['user'], $result['verification_token']];

        MailService::sendVerificationEmail($email, $data['name'], $verificationRawToken);

        return $user;
    }


    private function attemptTransactionalSignup(array $data, string $email): ?array
    {
        $session = Database::client()->startSession();

        try {
            $session->startTransaction();

            try {
                $result = $this->createUserCompanyTeamChannel($data, $email, $session);
                $session->commitTransaction();
                return $result;
            } catch (Throwable $e) {
                try {
                    $session->abortTransaction();
                } catch (Throwable) {

                }

                if ($this->isTransactionsUnsupportedError($e)) {
                    return null;
                }

                throw $e;
            }
        } finally {
            $session->endSession();
        }
    }

    private function isTransactionsUnsupportedError(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Transaction numbers are only allowed on a replica set member or mongos')
            || str_contains($message, 'IllegalOperation')
            || str_contains($message, 'transaction')
                && str_contains($message, 'not support');
    }

    private function createUserCompanyTeamChannel(array $data, string $email, $session): array
    {
        $options = ['session' => $session];

        $userDoc = [
            'name' => $data['name'],
            'email' => $email,
            'password_hash' => Security::hashPassword($data['password']),
            'company_id' => null,
            'email_verified_at' => null,
            'status' => self::STATUS_PENDING,
        ];
        $userInserted = $this->insertWithOptions('users', $userDoc, $options);
        $userId = $userInserted['_id'];

        $companyDoc = [
            'name' => $data['company_name'],
            'created_by' => $userId,
        ];
        $companyInserted = $this->insertWithOptions('companies', $companyDoc, $options);
        $companyId = $companyInserted['_id'];

        $this->updateWithOptions('users', $userId, ['company_id' => $companyId], $options);

        $teamDoc = [
            'name' => 'General Team',
            'company_id' => $companyId,
            'created_by' => $userId,
        ];
        $teamInserted = $this->insertWithOptions('teams', $teamDoc, $options);
        $teamId = $teamInserted['_id'];

        $this->insertWithOptions('team_members', [
            'team_id' => $teamId,
            'user_id' => $userId,
            'added_by' => $userId,
            'created_at' => new UTCDateTime(),
        ], $options, addTimestamps: false);

        $channelDoc = [
            'name' => 'Announcements',
            'team_id' => $teamId,
            'created_by' => $userId,
            'visibility' => 'public',
        ];
        $channelInserted = $this->insertWithOptions('channels', $channelDoc, $options);
        $channelId = $channelInserted['_id'];

        $this->insertWithOptions('channel_members', [
            'channel_id' => $channelId,
            'user_id' => $userId,
            'added_by' => $userId,
            'created_at' => new UTCDateTime(),
        ], $options, addTimestamps: false);

        $rawToken = TokenGenerator::generate();
        $ttl = (int) Env::get('EMAIL_VERIFICATION_TOKEN_TTL_MINUTES', 60);
        $expiresAt = new UTCDateTime((time() + $ttl * 60) * 1000);

        $this->insertWithOptions('email_verification_tokens', [
            'user_id' => $userId,
            'token_hash' => TokenGenerator::hash($rawToken),
            'expires_at' => $expiresAt,
            'used_at' => null,
        ], $options);

        $userDoc['_id'] = $userId;
        $userDoc['company_id'] = $companyId;

        return ['user' => $userDoc, 'verification_token' => $rawToken];
    }

    private function insertWithOptions(string $collection, array $doc, array $options, bool $addTimestamps = true): array
    {
        if ($addTimestamps) {
            $now = new UTCDateTime();
            $doc['created_at'] = $now;
            $doc['updated_at'] = $now;
        }

        $result = Database::collection($collection)->insertOne($doc, $options);
        $doc['_id'] = $result->getInsertedId();
        return $doc;
    }

    private function updateWithOptions(string $collection, ObjectId $id, array $fields, array $options): void
    {
        $fields['updated_at'] = new UTCDateTime();
        Database::collection($collection)->updateOne(['_id' => $id], ['$set' => $fields], $options);
    }



    private function createUserCompanyTeamChannelNoSession(array $data, string $email, array &$created): array
    {
        $now = fn () => new UTCDateTime();

        $userDoc = [
            'name' => $data['name'],
            'email' => $email,
            'password_hash' => Security::hashPassword($data['password']),
            'company_id' => null,
            'email_verified_at' => null,
            'status' => self::STATUS_PENDING,
            'created_at' => $now(),
            'updated_at' => $now(),
        ];
        $userId = Database::collection('users')->insertOne($userDoc)->getInsertedId();
        $created[] = ['users', $userId];

        $companyDoc = [
            'name' => $data['company_name'],
            'created_by' => $userId,
            'created_at' => $now(),
            'updated_at' => $now(),
        ];
        $companyId = Database::collection('companies')->insertOne($companyDoc)->getInsertedId();
        $created[] = ['companies', $companyId];

        Database::collection('users')->updateOne(
            ['_id' => $userId],
            ['$set' => ['company_id' => $companyId, 'updated_at' => $now()]]
        );

        $teamDoc = [
            'name' => 'General Team',
            'company_id' => $companyId,
            'created_by' => $userId,
            'created_at' => $now(),
            'updated_at' => $now(),
        ];
        $teamId = Database::collection('teams')->insertOne($teamDoc)->getInsertedId();
        $created[] = ['teams', $teamId];

        $teamMemberDoc = [
            'team_id' => $teamId,
            'user_id' => $userId,
            'added_by' => $userId,
            'created_at' => $now(),
        ];
        $tmId = Database::collection('team_members')->insertOne($teamMemberDoc)->getInsertedId();
        $created[] = ['team_members', $tmId];

        $channelDoc = [
            'name' => 'Announcements',
            'team_id' => $teamId,
            'created_by' => $userId,
            'visibility' => 'public',
            'created_at' => $now(),
            'updated_at' => $now(),
        ];
        $channelId = Database::collection('channels')->insertOne($channelDoc)->getInsertedId();
        $created[] = ['channels', $channelId];

        $channelMemberDoc = [
            'channel_id' => $channelId,
            'user_id' => $userId,
            'added_by' => $userId,
            'created_at' => $now(),
        ];
        $cmId = Database::collection('channel_members')->insertOne($channelMemberDoc)->getInsertedId();
        $created[] = ['channel_members', $cmId];

        $rawToken = TokenGenerator::generate();
        $ttl = (int) Env::get('EMAIL_VERIFICATION_TOKEN_TTL_MINUTES', 60);
        $expiresAt = new UTCDateTime((time() + $ttl * 60) * 1000);

        $evtDoc = [
            'user_id' => $userId,
            'token_hash' => TokenGenerator::hash($rawToken),
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => $now(),
        ];
        $evtId = Database::collection('email_verification_tokens')->insertOne($evtDoc)->getInsertedId();
        $created[] = ['email_verification_tokens', $evtId];

        $userDoc['_id'] = $userId;
        $userDoc['company_id'] = $companyId;

        return ['user' => $userDoc, 'verification_token' => $rawToken];
    }

    public function verifyEmail(string $rawToken): array
    {
        $hash = TokenGenerator::hash($rawToken);
        $tokenDoc = $this->verificationTokens->findValidByHash($hash);

        if ($tokenDoc === null) {
            throw new AuthenticationException('This verification token is invalid or has expired.');
        }

        $user = $this->users->findById((string) $tokenDoc['user_id']);
        if ($user === null) {
            throw new NotFoundException('User not found.');
        }

        if (!empty($user['email_verified_at'])) {
            throw new ConflictException('This account has already been verified.');
        }

        $this->users->updateById((string) $user['_id'], [
            'email_verified_at' => new UTCDateTime(),
            'status' => self::STATUS_ACTIVE,
        ]);

        $this->verificationTokens->markUsed($tokenDoc['_id']);

        return $this->users->findById((string) $user['_id']);
    }

    public function resendVerification(string $email): void
    {
        $user = $this->users->findByEmail($email);

        // Do not reveal whether the email exists - respond the same way either way.
        if ($user === null) {
            return;
        }

        if (!empty($user['email_verified_at'])) {
            throw new ConflictException('This account has already been verified.');
        }

        $rawToken = TokenGenerator::generate();
        $ttl = (int) Env::get('EMAIL_VERIFICATION_TOKEN_TTL_MINUTES', 60);
        $expiresAt = new UTCDateTime((time() + $ttl * 60) * 1000);

        $userId = $user['_id'] instanceof ObjectId ? $user['_id'] : new ObjectId((string) $user['_id']);
        $this->verificationTokens->create($userId, TokenGenerator::hash($rawToken), $expiresAt);

        MailService::sendVerificationEmail($user['email'], $user['name'], $rawToken);
    }

    public function login(string $email, string $password): array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || !Security::verifyPassword($password, $user['password_hash'])) {
            throw new AuthenticationException('Invalid email or password.');
        }

        if (empty($user['email_verified_at'])) {
            throw new AuthenticationException('Please verify your email address before logging in.');
        }

        $rawToken = TokenGenerator::generate();
        $ttl = (int) Env::get('AUTH_TOKEN_TTL_MINUTES', 1440);
        $expiresAt = new UTCDateTime((time() + $ttl * 60) * 1000);

        $userId = $user['_id'] instanceof ObjectId ? $user['_id'] : new ObjectId((string) $user['_id']);

        $this->authTokens->create($userId, TokenGenerator::hash($rawToken), $expiresAt);

        return ['user' => $user, 'token' => $rawToken, 'expires_at' => $expiresAt];
    }

    public function logout(string $rawToken): void
    {
        $this->authTokens->revokeByHash(TokenGenerator::hash($rawToken));
    }

    public function userFromToken(string $rawToken): array
    {
        $hash = TokenGenerator::hash($rawToken);
        $tokenDoc = $this->authTokens->findActiveByHash($hash);

        if ($tokenDoc === null) {
            throw new AuthenticationException('Invalid, expired, or revoked token.');
        }

        $user = $this->users->findById((string) $tokenDoc['user_id']);
        if ($user === null) {
            throw new AuthenticationException('User associated with this token no longer exists.');
        }

        return $user;
    }
}
