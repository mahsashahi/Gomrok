<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Jobs;

use Gomrok\Jobs\PurgeExpiredIdempotencyKeys;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryIdempotencyStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PurgeExpiredIdempotencyKeysTest extends TestCase
{
    #[Test]
    public function deletesOnlyRowsPastTheirExpiry(): void
    {
        $clock = new FrozenClock('2026-09-08T12:00:00+00:00');
        $store = new InMemoryIdempotencyStore();

        $store->claim(1, 'stale', 'fp', $clock->now(), $clock->now()->modify('+1 hour'));
        $store->claim(1, 'fresh', 'fp', $clock->now(), $clock->now()->modify('+2 days'));

        $clock->advanceSeconds(86_400); // +1 day: 'stale' is now expired, 'fresh' is not

        $deleted = (new PurgeExpiredIdempotencyKeys($store, $clock, new NullLogger()))();

        self::assertSame(1, $deleted);
        // 'fresh' survived — claiming it again still sees the in-flight row.
        self::assertNotNull($store->claim(1, 'fresh', 'fp', $clock->now(), $clock->now()->modify('+1 day')));
        // 'stale' is gone — claiming it again is a fresh claim.
        self::assertNull($store->claim(1, 'stale', 'fp', $clock->now(), $clock->now()->modify('+1 day')));
    }
}
