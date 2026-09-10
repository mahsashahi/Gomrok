<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Idempotency;

use DateTimeImmutable;

/**
 * Port for persisting idempotency keys. The MySQL adapter lives in
 * `Shared\Infrastructure\Persistence`; application / HTTP code depends only on
 * this interface.
 */
interface IdempotencyStore
{
    /**
     * Claim `(clientId, key)` for the current request.
     *
     * Returns `null` when the caller now owns the key and should proceed — this
     * covers a brand-new key, an expired row, and a previously `Failed` row
     * (both are reset to `Processing` with the new fingerprint).
     *
     * Returns an {@see IdempotencyRecord} when the key is already `Processing`
     * (a concurrent request) or `Done` (a completed one). The caller inspects
     * `status` and `matchesFingerprint()` to decide between replay, 409 and 422.
     */
    public function claim(
        int $clientId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): ?IdempotencyRecord;

    /**
     * Mark a claimed key as completed and record what it produced. `targetType`
     * / `targetId` are null when the handler did not register a created entity
     * (see {@see \Gomrok\Shared\Http\IdempotencyContext}).
     */
    public function markCompleted(
        int $clientId,
        string $key,
        ?string $targetType,
        ?int $targetId,
        int $responseStatus,
        DateTimeImmutable $now,
    ): void;

    /**
     * Mark a claimed key as failed so a later attempt can reclaim it.
     */
    public function markFailed(int $clientId, string $key, DateTimeImmutable $now): void;

    /**
     * Delete every row whose `expires_at` is at or before `$now`. Returns the
     * number of rows removed.
     */
    public function purgeExpired(DateTimeImmutable $now): int;
}
