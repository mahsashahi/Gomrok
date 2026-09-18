<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

use DateTimeImmutable;

/**
 * The exponential retry schedule for client notification delivery (Phase 28
 * Q4, user-specified) — richer than the flatter webhook-retry precedent
 * (`MAX_ATTEMPTS = 5`, no computed delay) because a client's server can
 * plausibly be down for hours, unlike a transient processing hiccup.
 *
 * 8 attempts total: 1m, 5m, 30m, 2h, 6h, 12h, 24h, 24h between them. After
 * the 8th failed attempt there is no 9th — the caller dead-letters instead
 * of calling {@see nextAttemptAt()} again.
 */
final class BackoffSchedule
{
    public const MAX_ATTEMPTS = 8;

    /** Minutes to wait before the (1-indexed) Nth attempt, given (N-1) prior failures. */
    private const DELAYS_MINUTES = [1, 5, 30, 120, 360, 720, 1440, 1440];

    /**
     * @param int $failedAttempts how many attempts have failed so far (>= 1) — the
     *                            delay before the *next* one. A first-ever attempt
     *                            needs no backoff; it's scheduled for `now` directly
     *                            by {@see \Gomrok\Modules\Notifications\Domain\ClientNotification::enqueue()}.
     */
    public static function nextAttemptAt(int $failedAttempts, DateTimeImmutable $now): DateTimeImmutable
    {
        $index = min(max($failedAttempts, 1) - 1, \count(self::DELAYS_MINUTES) - 1);

        return $now->modify('+' . self::DELAYS_MINUTES[$index] . ' minutes');
    }

    public static function isExhausted(int $attemptsSoFar): bool
    {
        return $attemptsSoFar >= self::MAX_ATTEMPTS;
    }
}
