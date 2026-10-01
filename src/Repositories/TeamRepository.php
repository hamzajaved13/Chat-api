<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use App\Support\Mongo;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class TeamRepository
{
    private Collection $collection;
    private Collection $members;

    public function __construct()
    {
        $this->collection = Database::collection('teams');
        $this->members = Database::collection('team_members');
    }

    public function findById(string $id): ?array
    {
        if (!Mongo::isValidObjectId($id)) {
            return null;
        }
        $doc = $this->collection->findOne(['_id' => new ObjectId($id)]);
        return $doc ? (array) $doc : null;
    }

    public function findByNameInCompany(string $name, ObjectId $companyId): ?array
    {
        $doc = $this->collection->findOne(['name' => $name, 'company_id' => $companyId]);
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
        $this->members->deleteMany(['team_id' => new ObjectId($id)]);
    }

    public function listByCompany(string $companyId): array
    {
        if (!Mongo::isValidObjectId($companyId)) {
            return [];
        }
        $cursor = $this->collection->find(['company_id' => new ObjectId($companyId)]);
        return array_map(fn ($doc) => (array) $doc, $cursor->toArray());
    }

    // --- membership ---------------------------------------------------

    public function addMember(ObjectId $teamId, ObjectId $userId, ObjectId $addedBy): bool
    {
        if ($this->isMember((string) $teamId, (string) $userId)) {
            return false;
        }

        $this->members->insertOne([
            'team_id' => $teamId,
            'user_id' => $userId,
            'added_by' => $addedBy,
            'created_at' => new UTCDateTime(),
        ]);

        return true;
    }

    public function removeMember(string $teamId, string $userId): bool
    {
        if (!Mongo::isValidObjectId($teamId) || !Mongo::isValidObjectId($userId)) {
            return false;
        }
        $result = $this->members->deleteOne([
            'team_id' => new ObjectId($teamId),
            'user_id' => new ObjectId($userId),
        ]);
        return $result->getDeletedCount() > 0;
    }

    public function isMember(string $teamId, string $userId): bool
    {
        if (!Mongo::isValidObjectId($teamId) || !Mongo::isValidObjectId($userId)) {
            return false;
        }
        $doc = $this->members->findOne([
            'team_id' => new ObjectId($teamId),
            'user_id' => new ObjectId($userId),
        ]);
        return $doc !== null;
    }

    public function listMembers(string $teamId): array
    {
        if (!Mongo::isValidObjectId($teamId)) {
            return [];
        }
        $cursor = $this->members->find(['team_id' => new ObjectId($teamId)]);
        return array_map(fn ($doc) => (array) $doc, $cursor->toArray());
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['company_id' => 1], ['name' => 'idx_team_company_id']);
        $this->members->createIndex(
            ['team_id' => 1, 'user_id' => 1],
            ['unique' => true, 'name' => 'uniq_team_user']
        );
        $this->members->createIndex(['user_id' => 1], ['name' => 'idx_team_members_user_id']);
    }
}
