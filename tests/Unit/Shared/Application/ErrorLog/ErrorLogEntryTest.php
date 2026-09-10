<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Application\ErrorLog;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogLevel;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ErrorLogEntryTest extends TestCase
{
    #[Test]
    public function fromThrowableCopiesMessageClassAndTrace(): void
    {
        $exception = new RuntimeException('provider timeout', 504);

        $entry = ErrorLogEntry::fromThrowable(
            $exception,
            'provider',
            ErrorLogLevel::Critical,
            clientId: 3,
            correlationId: 'corr-9',
            context: ['provider' => 'stripe'],
        );

        self::assertSame(ErrorLogLevel::Critical, $entry->level);
        self::assertSame('provider', $entry->source);
        self::assertSame('provider timeout', $entry->message);
        self::assertSame(RuntimeException::class, $entry->exceptionClass);
        self::assertSame('504', $entry->code);
        self::assertSame(3, $entry->clientId);
        self::assertSame('corr-9', $entry->correlationId);
        self::assertSame(['provider' => 'stripe'], $entry->context);
        self::assertNotNull($entry->stackTrace);
    }

    #[Test]
    public function fromThrowableNormalisesAZeroCodeToNull(): void
    {
        $entry = ErrorLogEntry::fromThrowable(new LogicException('bug'), 'job');

        self::assertNull($entry->code);
        self::assertSame(ErrorLogLevel::Error, $entry->level);
    }
}
