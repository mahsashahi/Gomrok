<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Idempotency;

/**
 * A stored idempotency key that already existed when a request tried to claim
 * it. Returned by {@see IdempotencyStore::claim()} only for keys that are
 * `Processing` or `Done` — expired and `Failed` rows are reclaimed in place and
 * reported as a fresh claim (null).
 */
final readonly class IdempotencyRecord
{
    public function __construct(
        public IdempotencyStatus $status,
        public string $requestFingerprint,
        public ?string $targetType,
        public ?int $targetId,
        public ?int $responseStatus,
    ) {
    }

    public function matchesFingerprint(string $fingerprint): bool
    {
        return hash_equals($this->requestFingerprint, $fingerprint);
    }
}
