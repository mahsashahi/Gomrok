<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Infrastructure;

use Gomrok\Shared\Infrastructure\SystemClock;
use Gomrok\Tests\Support\FrozenClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    #[Test]
    public function returnsUtcNow(): void
    {
        $clock = new SystemClock();

        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $now = $clock->now();
        $after = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        self::assertSame('UTC', $now->getTimezone()->getName());
        self::assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }

    #[Test]
    public function frozenClockDoesNotMoveUntilAdvanced(): void
    {
        $clock = new FrozenClock('2026-06-01T12:00:00+00:00');

        self::assertSame($clock->now()->getTimestamp(), $clock->now()->getTimestamp());

        $t0 = $clock->now();
        $clock->advanceSeconds(90);
        self::assertSame(90, $clock->now()->getTimestamp() - $t0->getTimestamp());
    }
}
