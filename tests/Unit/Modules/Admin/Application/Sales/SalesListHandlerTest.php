<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Sales;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\PaymentProviderResolver;
use Gomrok\Modules\Admin\Application\Sales\SalesListHandler;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Payments\Domain\ProviderTransaction;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentDirectory;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SalesListHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private DateTimeImmutable $now;
    private InMemoryPaymentRepository $paymentsRepo;
    private InMemoryPaymentAttemptRepository $attemptsRepo;
    private InMemoryProviderTransactionRepository $transactionsRepo;
    private SalesListHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $this->paymentsRepo = new InMemoryPaymentRepository();
        $this->attemptsRepo = new InMemoryPaymentAttemptRepository();
        $this->transactionsRepo = new InMemoryProviderTransactionRepository();

        $this->handler = new SalesListHandler(
            new InMemoryPaymentDirectory($this->paymentsRepo),
            $this->attemptsRepo,
            $this->transactionsRepo,
            new PaymentProviderResolver(new InMemoryProviderRoutingDecisionSnapshotRepository(), new StubProviderAccountDirectory()),
        );
    }

    #[Test]
    public function tabsReflectRealStatusCountsPlusAll(): void
    {
        $this->seedPayment(PaymentStatus::Paid);
        $this->seedPayment(PaymentStatus::Paid);
        $this->seedPayment(PaymentStatus::Failed);

        $result = $this->handler->forClient(self::CLIENT, null);

        $byKey = [];
        foreach ($result->tabs as $tab) {
            $byKey[$tab->key] = $tab->count;
        }

        self::assertSame(3, $byKey['all']);
        self::assertSame(2, $byKey['paid']);
        self::assertSame(1, $byKey['failed']);
    }

    #[Test]
    public function filteringByStatusNarrowsTheRowsButNotTheTabCounts(): void
    {
        $this->seedPayment(PaymentStatus::Paid);
        $this->seedPayment(PaymentStatus::Failed);

        $result = $this->handler->forClient(self::CLIENT, 'failed');

        self::assertCount(1, $result->rows);
        self::assertSame('Failed', $result->rows[0]->status);
        self::assertSame(1, $result->totalCount);

        $allTab = null;
        foreach ($result->tabs as $tab) {
            if ($tab->key === 'all') {
                $allTab = $tab;
            }
        }
        self::assertNotNull($allTab);
        self::assertSame(2, $allTab->count);
    }

    #[Test]
    public function theActiveTabMatchesTheAppliedFilter(): void
    {
        $this->seedPayment(PaymentStatus::Paid);

        $result = $this->handler->forClient(self::CLIENT, 'paid');

        foreach ($result->tabs as $tab) {
            self::assertSame($tab->key === 'paid', $tab->active);
        }
    }

    #[Test]
    public function aRowsTimelineFlattensAllProviderTransactionsAcrossAttemptsChronologically(): void
    {
        $paymentId = $this->seedPayment(PaymentStatus::Paid);

        $attempt = PaymentAttempt::start($paymentId, 1, 1, PaymentMethod::Card, $this->now);
        $this->attemptsRepo->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $this->transactionsRepo->save(ProviderTransaction::record($attemptId, 'authorize', null, null, 'open', $this->now));
        $this->transactionsRepo->save(ProviderTransaction::record($attemptId, 'capture', null, null, 'succeeded', $this->now->modify('+1 minute')));

        $result = $this->handler->forClient(self::CLIENT, null);

        self::assertCount(2, $result->rows[0]->timeline);
        self::assertSame('Authorize', $result->rows[0]->timeline[0]->kind);
        self::assertSame('Capture', $result->rows[0]->timeline[1]->kind);
    }

    #[Test]
    public function aPaymentWithNoAttemptsHasAnEmptyTimeline(): void
    {
        $this->seedPayment(PaymentStatus::Created);

        $result = $this->handler->forClient(self::CLIENT, null);

        self::assertSame([], $result->rows[0]->timeline);
    }

    private function seedPayment(PaymentStatus $status): int
    {
        $payment = Payment::create(self::CLIENT, null, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        if ($status !== PaymentStatus::Created) {
            $payment->transitionTo(PaymentStatus::Pending, $this->now);
        }
        if ($status !== PaymentStatus::Created && $status !== PaymentStatus::Pending) {
            $payment->transitionTo($status, $this->now);
        }
        $this->paymentsRepo->save($payment);

        $id = $payment->id();
        \assert($id !== null);

        return $id;
    }
}
