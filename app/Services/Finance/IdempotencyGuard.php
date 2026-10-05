<?php

namespace App\Services\Finance;

use App\Exceptions\IdempotencyConflictException;

/**
 * Request fingerprinting for idempotent financial operations.
 *  - same key + same request      => the original result is returned safely
 *  - same key + different request => IdempotencyConflictException
 * Rows written before fingerprints existed (null hash) are treated as matching.
 */
final class IdempotencyGuard
{
    /** @param array<string, scalar|null> $parts operation, actor and the economically relevant request fields */
    public static function hash(array $parts): string
    {
        ksort($parts);

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public static function assertMatches(?string $storedHash, string $requestHash): void
    {
        if ($storedHash !== null && ! hash_equals($storedHash, $requestHash)) {
            throw new IdempotencyConflictException;
        }
    }
}
