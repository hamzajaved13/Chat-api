<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use App\Support\Mongo;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class UserRepository
{
    private Collection $collection;

    public function __construct()
    {
        $this->collection = Database::collection('users');
    }

    public function findByEmail(string $email): ?array
    {
        $doc = $this->collection->findOne(['email' => strtolower(trim($email))]);
        return $doc ? (array) $doc : null;
    }

    public function findById(string $id): ?array
    {
        if (!Mongo::isValidObjectId($id)) {
            return null;
        }
        $doc = $this->collection->findOne(['_id' => new ObjectId($id)]);
        return $doc ? (array) $doc : null;
    }

    public function findManyByIds(array $ids): array
    {
        $objectIds = array_values(array_filter(array_map(
            fn ($id) => Mongo::isValidObjectId((string) $id) ? new ObjectId((string) $id) : null,
            $ids
        )));

        if (empty($objectIds)) {
            return [];
        }

        $cursor = $this->collection->find(['_id' => ['$in' => $objectIds]]);
        return array_map(fn ($doc) => (array) $doc, $cursor->toArray());
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

    public function updateById(string $id, array $fields): void
    {
        if (!Mongo::isValidObjectId($id)) {
            return;
        }
        $fields['updated_at'] = new UTCDateTime();
        $this->collection->updateOne(
            ['_id' => new ObjectId($id)],
            ['$set' => $fields]
        );
    }

    public function listByCompany(string $companyId): array
    {
        if (!Mongo::isValidObjectId($companyId)) {
            return [];
        }
        $cursor = $this->collection->find(['company_id' => new ObjectId($companyId)]);
        return array_map(fn ($doc) => (array) $doc, $cursor->toArray());
    }

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['email' => 1], ['unique' => true, 'name' => 'uniq_email']);
        $this->collection->createIndex(['company_id' => 1], ['name' => 'idx_company_id']);
    }
}
