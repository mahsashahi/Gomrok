<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Application\ChangeCheckoutAttemptStatus\ChangeCheckoutAttemptStatusHandler;
use Gomrok\Modules\Checkout\Application\Jobs\CheckoutAbandonmentSweepHandler;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckoutAbandonmentSweepHandlerTest extends TestCase
{
    private InMemoryCheckoutAttemptRepository $attempts;
    private FrozenClock $clock;
    private CheckoutAbandonmentSweepHandler $handler;

    protected function setUp(): void
    {
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->clock = new FrozenClock('2026-09-17T12:00:00+00:00');

        $changeStatus = new ChangeCheckoutAttemptStatusHandler(
            $this->attempts,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            $this->clock,
        );

        $this->handler = new CheckoutAbandonmentSweepHandler($this->attempts, $changeStatus, $this->clock);
    }

    private function seedAttempt(string $createdAt, string $attemptReference = 'ca-1', int $clientId = 1): int
    {
        $attempt = CheckoutAttempt::start(
            $clientId,
            null,
            $attemptReference,
            42,
            'US',
            'USD',
            null,
            null,
            null,
            new DateTimeImmutable($createdAt),
        );
        $this->attempts->save($attempt);
        $id = $attempt->id();
        \assert($id !== null);

        return $id;
    }

    #[Test]
    public function exposesItsTypeAndRecurrence(): void
    {
        self::assertSame('checkout_abandonment_sweep', $this->handler->type());
        self::assertSame(15, $this->handler->recurrenceIntervalMinutes());
    }

    #[Test]
    public function abandonsAStaleNonTerminalAttemptAndTalliesTheSummary(): void
    {
        $id = $this->seedAttempt('2026-09-17T10:00:00+00:00');

        $job = Job::schedule('checkout_abandonment_sweep', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertTrue($result->success);
        self::assertSame(['scanned' => 1, 'abandoned' => 1], $result->summary);

        $attempt = $this->attempts->findById($id);
        self::assertNotNull($attempt);
        self::assertSame('abandoned', $attempt->status()->value);
    }

    #[Test]
    public function leavesAFreshAttemptUntouched(): void
    {
        $this->seedAttempt('2026-09-17T11:55:00+00:00');

        $job = Job::schedule('checkout_abandonment_sweep', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertSame(['scanned' => 0, 'abandoned' => 0], $result->summary);
    }
}
