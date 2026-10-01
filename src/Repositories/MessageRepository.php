<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use App\Support\Mongo;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class MessageRepository
{
    private Collection $collection;

    public function __construct()
    {
        $this->collection = Database::collection('messages');
    }

    public function findById(string $id): ?array
    {
        if (!Mongo::isValidObjectId($id)) {
            return null;
        }
        $doc = $this->collection->findOne([
            '_id' => new ObjectId($id),
            'deleted_at' => null,
        ]);
        return $doc ? (array) $doc : null;
    }

    public function create(array $data): array
    {
        $now = new UTCDateTime();
        $payload = array_merge($data, [
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $result = $this->collection->insertOne($payload);
        $payload['_id'] = $result->getInsertedId();
        return $payload;
    }

    public function update(string $id, array $fields): void
    {
        if (!Mongo::isValidObjectId($id)) {
            return;
        }
        $fields['updated_at'] = new UTCDateTime();
        $this->collection->updateOne(['_id' => new ObjectId($id)], ['$set' => $fields]);
    }

    public function softDelete(string $id): void
    {
        $this->update($id, ['deleted_at' => new UTCDateTime()]);
    }

    public function listByChannel(string $channelId, int $limit = 50, int $skip = 0): array
    {
        if (!Mongo::isValidObjectId($channelId)) {
            return [];
        }

        $cursor = $this->collection->find(
            ['channel_id' => new ObjectId($channelId), 'deleted_at' => null],
            ['sort' => ['created_at' => -1], 'limit' => $limit, 'skip' => $skip]
        );

        return array_map(fn ($doc) => (array) $doc, $cursor->toArray());
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(
            ['channel_id' => 1, 'created_at' => -1],
            ['name' => 'idx_messages_channel_created']
        );
    }
}
