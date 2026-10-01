<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Converts MongoDB documents (BSONDocument/ObjectId/UTCDateTime) into plain,
 * JSON-safe arrays, and strips fields that must never leave the API
 * (password hashes, raw/hashed tokens, etc).
 */
final class Presenter
{
    public static function toArray(mixed $document): array
    {
        if ($document === null) {
            return [];
        }

        $array = json_decode(json_encode($document), true);
        return is_array($array) ? $array : [];
    }

    public static function user(mixed $document): array
    {
        $user = self::toArray($document);

        unset($user['password_hash']);

        $user['id'] = $user['_id']['$oid'] ?? $user['id'] ?? null;
        unset($user['_id']);

        return $user;
    }

    public static function company(mixed $document): array
    {
        $company = self::toArray($document);
        $company['id'] = $company['_id']['$oid'] ?? $company['id'] ?? null;
        unset($company['_id']);
        return $company;
    }

    public static function team(mixed $document): array
    {
        $team = self::toArray($document);
        $team['id'] = $team['_id']['$oid'] ?? $team['id'] ?? null;
        unset($team['_id']);
        return $team;
    }

    public static function channel(mixed $document): array
    {
        $channel = self::toArray($document);
        $channel['id'] = $channel['_id']['$oid'] ?? $channel['id'] ?? null;
        unset($channel['_id']);
        return $channel;
    }

    public static function message(mixed $document): array
    {
        $message = self::toArray($document);
        $message['id'] = $message['_id']['$oid'] ?? $message['id'] ?? null;
        unset($message['_id']);
        return $message;
    }

    public static function collection(array $documents, callable $mapper): array
    {
        return array_values(array_map($mapper, $documents));
    }
}
