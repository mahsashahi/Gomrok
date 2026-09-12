<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentAttemptStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PaymentAttemptTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
    }

    #[Test]
    public function startsInTheStartedState(): void
    {
        $attempt = PaymentAttempt::start(1, 2, 1, null, $this->now);

        self::assertSame(PaymentAttemptStatus::Started, $attempt->status());
        self::assertSame(1, $attempt->attemptNumber());
    }

    #[Test]
    public function canSucceedOnceFromStarted(): void
    {
        $attempt = PaymentAttempt::start(1, 2, 1, null, $this->now);

        self::assertNull($attempt->succeed($this->now));
        self::assertSame(PaymentAttemptStatus::Succeeded, $attempt->status());
    }

    #[Test]
    public function canFailOnceFromStartedWithAnErrorCodeAndMessage(): void
    {
        $attempt = PaymentAttempt::start(1, 2, 1, null, $this->now);

        self::assertNull($attempt->fail($this->now, 'provider_declined', 'Card declined'));
        self::assertSame(PaymentAttemptStatus::Failed, $attempt->status());
        self::assertSame('provider_declined', $attempt->errorCode());
        self::assertSame('Card declined', $attempt->errorMessage());
    }

    #[Test]
    public function cannotBeCompletedTwice(): void
    {
        $attempt = PaymentAttempt::start(1, 2, 1, null, $this->now);
        $attempt->succeed($this->now);

        self::assertSame('payment_attempt.already_completed', $attempt->fail($this->now, 'x', 'y')?->code);
        self::assertSame(PaymentAttemptStatus::Succeeded, $attempt->status());
    }
}
