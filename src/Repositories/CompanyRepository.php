<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;
use MongoDB\BSON\ObjectId;
use App\Support\Mongo;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

final class CompanyRepository
{
    private Collection $collection;

    public function __construct()
    {
        $this->collection = Database::collection('companies');
    }

    public function findById(string $id): ?array
    {
        if (!Mongo::isValidObjectId($id)) {
            return null;
        }
        $doc = $this->collection->findOne(['_id' => new ObjectId($id)]);
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

    public function ensureIndexes(): void
    {
        $this->collection->createIndex(['name' => 1], ['name' => 'idx_company_name']);
    }
}
