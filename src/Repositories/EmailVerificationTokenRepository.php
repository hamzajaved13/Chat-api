<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class EmailVerificationTokenRepository
{
    private Collection $collection;

    public function __construct()
    {
        $this->collection = Database::collection('email_verification_tokens');
    }

    public function create(ObjectId $userId, string $tokenHash, UTCDateTime $expiresAt): array
    {
        // Invalidate any previous outstanding tokens for this user first,
        // so only the most recently issued token/link is usable.
        $this->collection->updateMany(
            ['user_id' => $userId, 'used_at' => null],
            ['$set' => ['used_at' => new UTCDateTime()]]
        );

        $payload = [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'created_at' => new UTCDateTime(),
        ];

        $result = $this->collection->insertOne($payload);
        $payload['_id'] = $result->getInsertedId();
        return $payload;
    }

    public function findValidByHash(string $tokenHash): ?array
    {
        $doc = $this->collection->findOne([
            'token_hash' => $tokenHash,
            'used_at' => null,
            'expires_at' => ['$gt' => new UTCDateTime()],
        ]);

        return $doc ? (array) $doc : null;
    }

    public function markUsed(ObjectId $id): void
    {
        $this->collection->updateOne(
            ['_id' => $id],
            ['$set' => ['used_at' => new UTCDateTime()]]
        );
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['token_hash' => 1], ['unique' => true, 'name' => 'uniq_evt_token_hash']);
        $this->collection->createIndex(['expires_at' => 1], ['name' => 'idx_evt_expiry']);
        $this->collection->createIndex(['user_id' => 1], ['name' => 'idx_evt_user_id']);
    }
}
