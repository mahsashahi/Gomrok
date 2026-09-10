<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Infrastructure;

use Gomrok\Shared\Infrastructure\CorrelationId;
use Gomrok\Shared\Infrastructure\Logging\CorrelationIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LoggingTest extends TestCase
{
    #[Test]
    public function processorAddsCorrelationIdAndStaticContext(): void
    {
        $correlationId = new CorrelationId();
        $correlationId->set('abc123');

        $processor = new CorrelationIdProcessor($correlationId, ['service' => 'gomrok', 'env' => 'testing']);

        $out = $processor($this->record());

        self::assertSame('abc123', $out->extra['correlation_id']);
        self::assertSame('gomrok', $out->extra['service']);
        self::assertSame('testing', $out->extra['env']);
    }

    #[Test]
    public function processorOmitsCorrelationIdWhenUnset(): void
    {
        $processor = new CorrelationIdProcessor(new CorrelationId(), ['service' => 'gomrok']);

        $out = $processor($this->record());

        self::assertArrayNotHasKey('correlation_id', $out->extra);
        self::assertSame('gomrok', $out->extra['service']);
    }

    #[Test]
    public function correlationIdGeneratesShortHexToken(): void
    {
        $token = CorrelationId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $token);
        self::assertNotSame($token, CorrelationId::generate());
    }

    private function record(): LogRecord
    {
        return new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'gomrok',
            level: Level::Info,
            message: 'payment.created',
            context: ['payment_id' => 42],
        );
    }
}
