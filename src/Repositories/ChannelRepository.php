<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use App\Support\Mongo;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class ChannelRepository
{
    private Collection $collection;
    private Collection $members;

    public function __construct()
    {
        $this->collection = Database::collection('channels');
        $this->members = Database::collection('channel_members');
    }

    public function findById(string $id): ?array
    {
        if (!Mongo::isValidObjectId($id)) {
            return null;
        }
        $doc = $this->collection->findOne(['_id' => new ObjectId($id)]);
        return $doc ? (array) $doc : null;
    }

    public function findByNameInTeam(string $name, ObjectId $teamId): ?array
    {
        $doc = $this->collection->findOne(['name' => $name, 'team_id' => $teamId]);
        return $doc ? (array) $doc : null;
    }

    public function create(array $data): array
    {
        $now = new UTCDateTime();
        $payload = array_merge($data, [
            'created_at' => $now,
            'updated_at' => $now,
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

    public function delete(string $id): void
    {
        if (!Mongo::isValidObjectId($id)) {
            return;
        }
        $this->collection->deleteOne(['_id' => new ObjectId($id)]);
        $this->members->deleteMany(['channel_id' => new ObjectId($id)]);
    }

    public function listByTeam(string $teamId): array
    {
        if (!Mongo::isValidObjectId($teamId)) {
            return [];
        }
        $cursor = $this->collection->find(['team_id' => new ObjectId($teamId)]);
        return array_map(fn ($doc) => (array) $doc, $cursor->toArray());
    }

    // --- membership ---------------------------------------------------

    public function addMember(ObjectId $channelId, ObjectId $userId, ObjectId $addedBy): bool
    {
        if ($this->isMember((string) $channelId, (string) $userId)) {
            return false;
        }

        $this->members->insertOne([
            'channel_id' => $channelId,
            'user_id' => $userId,
            'added_by' => $addedBy,
            'created_at' => new UTCDateTime(),
        ]);

        return true;
    }

    public function removeMember(string $channelId, string $userId): bool
    {
        if (!Mongo::isValidObjectId($channelId) || !Mongo::isValidObjectId($userId)) {
            return false;
        }
        $result = $this->members->deleteOne([
            'channel_id' => new ObjectId($channelId),
            'user_id' => new ObjectId($userId),
        ]);
        return $result->getDeletedCount() > 0;
    }

    public function isMember(string $channelId, string $userId): bool
    {
        if (!Mongo::isValidObjectId($channelId) || !Mongo::isValidObjectId($userId)) {
            return false;
        }
        $doc = $this->members->findOne([
            'channel_id' => new ObjectId($channelId),
            'user_id' => new ObjectId($userId),
        ]);
        return $doc !== null;
    }

    public function listMembers(string $channelId): array
    {
        if (!Mongo::isValidObjectId($channelId)) {
            return [];
        }
        $cursor = $this->members->find(['channel_id' => new ObjectId($channelId)]);
        return array_map(fn ($doc) => (array) $doc, $cursor->toArray());
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['team_id' => 1], ['name' => 'idx_channel_team_id']);
        $this->members->createIndex(
            ['channel_id' => 1, 'user_id' => 1],
            ['unique' => true, 'name' => 'uniq_channel_user']
        );
        $this->members->createIndex(['user_id' => 1], ['name' => 'idx_channel_members_user_id']);
    }
}
