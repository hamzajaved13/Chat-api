<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

/**
 * Stores only a SHA-256 hash of each auth token, never the raw value
 * (see Support\TokenGenerator). Lookups hash the incoming Bearer token
 * and compare against token_hash.
 */
final class AuthTokenRepository
{
    private Collection $collection;

    public function __construct()
    {
        $this->collection = Database::collection('auth_tokens');
    }

    public function create(ObjectId $userId, string $tokenHash, UTCDateTime $expiresAt): array
    {
        $payload = [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'revoked_at' => null,
            'created_at' => new UTCDateTime(),
        ];

        $result = $this->collection->insertOne($payload);
        $payload['_id'] = $result->getInsertedId();
        return $payload;
    }

    public function findActiveByHash(string $tokenHash): ?array
    {
        $doc = $this->collection->findOne([
            'token_hash' => $tokenHash,
            'revoked_at' => null,
            'expires_at' => ['$gt' => new UTCDateTime()],
        ]);

        return $doc ? (array) $doc : null;
    }

    public function revokeByHash(string $tokenHash): void
    {
        $this->collection->updateOne(
            ['token_hash' => $tokenHash, 'revoked_at' => null],
            ['$set' => ['revoked_at' => new UTCDateTime()]]
        );
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['token_hash' => 1], ['unique' => true, 'name' => 'uniq_token_hash']);
        $this->collection->createIndex(['expires_at' => 1], ['name' => 'idx_auth_token_expiry']);
        $this->collection->createIndex(['user_id' => 1], ['name' => 'idx_auth_token_user_id']);
    }
}
