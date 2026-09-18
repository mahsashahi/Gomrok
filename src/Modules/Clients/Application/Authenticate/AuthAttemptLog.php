<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\Authenticate;

use DateTimeImmutable;

/**
 * Appends to `client_auth_attempts`. Best-effort — the authenticator swallows a
 * write failure (it must not turn a valid auth into a 500).
 */
interface AuthAttemptLog
{
    public function record(AuthAttempt $attempt, DateTimeImmutable $at): void;

    /**
     * Count of failed attempts for `$keyId` since `$since` — the lockout
     * check reads this (Phase 30A Q1, mirrors `AdminLoginAttemptRepository::countFailedSince()`).
     */
    public function countFailedSince(string $keyId, DateTimeImmutable $since): int;
}
