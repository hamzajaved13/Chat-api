<?php

declare(strict_types=1);

namespace App\Support;

use MongoDB\BSON\ObjectId;

/**
 * MongoDB\BSON\ObjectId::isValid() existed in ext-mongodb v1.x but was
 * removed in the v2.x rewrite (see the current class synopsis at
 * https://www.php.net/class.mongodb-bson-objectid - only __construct,
 * getTimestamp, jsonSerialize and __toString remain). Every repository
 * needs to validate a client-supplied string before building a query
 * with it, so that logic lives here once instead of being duplicated
 * (and instead of depending on a driver method that may or may not
 * exist depending on the installed extension version).
 */
final class Mongo
{
    private const OBJECT_ID_PATTERN = '/^[a-f0-9]{24}$/i';

    public static function isValidObjectId(mixed $id): bool
    {
        if ($id instanceof ObjectId) {
            return true;
        }

        if (!is_string($id)) {
            return false;
        }

        // The regex alone is enough to guarantee ObjectId's constructor
        // will not throw, but we still construct it to be certain, since
        // this is the same guard InvalidArgumentException-avoidance
        // ObjectId::isValid() used to provide.
        if (!preg_match(self::OBJECT_ID_PATTERN, $id)) {
            return false;
        }

        try {
            new ObjectId($id);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
