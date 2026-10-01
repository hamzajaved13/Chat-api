<?php

declare(strict_types=1);

namespace App\Config;

use MongoDB\Client;
use MongoDB\Database as MongoDatabase;
use MongoDB\Collection;


final class Database
{
    private static ?Client $client = null;
    private static ?MongoDatabase $database = null;

    public static function client(): Client
    {
        if (self::$client === null) {
            $uri = Env::get('MONGO_URI', 'mongodb://127.0.0.1:27017');
            self::$client = new Client($uri);
        }

        return self::$client;
    }

    public static function db(): MongoDatabase
    {
        if (self::$database === null) {
            $dbName = Env::get('MONGO_DB_NAME', 'chat_app');
            self::$database = self::client()->selectDatabase($dbName);
        }

        return self::$database;
    }

    public static function collection(string $name): Collection
    {
        return self::db()->selectCollection($name);
    }
}
