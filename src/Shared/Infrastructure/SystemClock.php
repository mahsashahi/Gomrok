<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * The real clock — always UTC. Tests use `Gomrok\Tests\Support\FrozenClock`.
 */
final class SystemClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
