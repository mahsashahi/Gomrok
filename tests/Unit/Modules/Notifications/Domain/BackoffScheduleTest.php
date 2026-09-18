<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Domain\BackoffSchedule;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 28 Q4 (user-specified): 1m, 5m, 30m, 2h, 6h, 12h, 24h, 24h across 8
 * attempts, then dead-lettered.
 */
final class BackoffScheduleTest extends TestCase
{
    #[Test]
    public function delaysMatchTheSpecifiedSchedule(): void
    {
        $now = new DateTimeImmutable('2026-09-17T00:00:00+00:00');
        $expectedMinutes = [1, 5, 30, 120, 360, 720, 1440, 1440];

        foreach ($expectedMinutes as $failedAttempts => $minutes) {
            $next = BackoffSchedule::nextAttemptAt($failedAttempts + 1, $now);
            self::assertEquals($now->modify("+{$minutes} minutes"), $next, 'failedAttempts=' . ($failedAttempts + 1));
        }
    }

    #[Test]
    public function isExhaustedAtTheEighthFailure(): void
    {
        self::assertFalse(BackoffSchedule::isExhausted(7));
        self::assertTrue(BackoffSchedule::isExhausted(8));
        self::assertTrue(BackoffSchedule::isExhausted(9));
    }

    #[Test]
    public function beyondTheScheduleReusesTheLastDelay(): void
    {
        $now = new DateTimeImmutable('2026-09-17T00:00:00+00:00');
        // Not normally reached (isExhausted(8) is true), but the schedule
        // should never throw or go out of bounds if called anyway.
        $next = BackoffSchedule::nextAttemptAt(20, $now);
        self::assertEquals($now->modify('+1440 minutes'), $next);
    }
}
