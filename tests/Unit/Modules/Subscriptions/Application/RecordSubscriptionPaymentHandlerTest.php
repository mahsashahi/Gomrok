<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Subscriptions\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentCommand;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentHandler;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentResult;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\InMemorySubscriptionEventRepository;
use Gomrok\Tests\Support\InMemorySubscriptionPaymentLinkRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecordSubscriptionPaymentHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemorySubscriptionRepository $subscriptions;
    private InMemoryPaymentRepository $payments;
    private InMemorySubscriptionPaymentLinkRepository $paymentLinks;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private RecordSubscriptionPaymentHandler $handler;
    private int $subscriptionId;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->subscriptions = new InMemorySubscriptionRepository();
        $this->payments = new InMemoryPaymentRepository();
        $this->paymentLinks = new InMemorySubscriptionPaymentLinkRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();

        $recordTransaction = new RecordProviderTransactionHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );

        $this->handler = new RecordSubscriptionPaymentHandler(
            $this->subscriptions,
            $this->attempts,
            $this->payments,
            $this->paymentLinks,
            new InMemorySubscriptionEventRepository(),
            $this->gatewayReferences,
            $recordTransaction,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );

        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $subscription = Subscription::create(self::CLIENT, 'user-1', $attemptId, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $this->now);
        $this->subscriptions->save($subscription);
        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);
        $this->subscriptionId = $subscriptionId;
    }

    #[Test]
    public function recordsASuccessfulRenewalChargeAsARealPayment(): void
    {
        $result = $this->handler->handle(new RecordSubscriptionPaymentCommand(
            self::CLIENT,
            $this->subscriptionId,
            'sub_charge_1',
            'paid',
            'paid',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RecordSubscriptionPaymentResult);
        self::assertSame('paid', $value->paymentStatus);
        self::assertSame('active', $value->subscriptionStatus);
        self::assertFalse($value->alreadyRecorded);

        $payment = $this->payments->findById($value->paymentId);
        self::assertNotNull($payment);
        self::assertNull($payment->checkoutAttemptId());
        self::assertSame('user-1', $payment->clientUserRef());
        self::assertSame(2900, $payment->amountMinor());
        self::assertSame(PurchaseType::Subscription, $payment->purchaseType());

        $link = $this->paymentLinks->findByPaymentId($value->paymentId);
        self::assertNotNull($link);
        self::assertSame($this->subscriptionId, $link->subscriptionId);

        $subscription = $this->subscriptions->findById($this->subscriptionId);
        self::assertSame(SubscriptionStatus::Active, $subscription?->status());
    }

    #[Test]
    public function aFailedRenewalChargeMovesTheSubscriptionToPastDue(): void
    {
        $result = $this->handler->handle(new RecordSubscriptionPaymentCommand(
            self::CLIENT,
            $this->subscriptionId,
            'sub_charge_2',
            'failed',
            'failed',
            errorCode: 'card_declined',
            errorMessage: 'The card was declined.',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof RecordSubscriptionPaymentResult);
        self::assertSame('failed', $value->paymentStatus);
        self::assertSame('past_due', $value->subscriptionStatus);

        $subscription = $this->subscriptions->findById($this->subscriptionId);
        self::assertNotNull($subscription);
        self::assertSame(SubscriptionStatus::PastDue, $subscription->status());
        self::assertSame('card_declined', $subscription->errorCode());
    }

    #[Test]
    public function aDuplicateChargeReferenceIsIdempotentAndDoesNotCreateASecondPayment(): void
    {
        $first = $this->handler->handle(new RecordSubscriptionPaymentCommand(self::CLIENT, $this->subscriptionId, 'sub_charge_3', 'paid', 'paid'));
        self::assertTrue($first->isOk());
        $firstValue = $first->value();
        \assert($firstValue instanceof RecordSubscriptionPaymentResult);

        $second = $this->handler->handle(new RecordSubscriptionPaymentCommand(self::CLIENT, $this->subscriptionId, 'sub_charge_3', 'paid', 'paid'));
        self::assertTrue($second->isOk());
        $secondValue = $second->value();
        \assert($secondValue instanceof RecordSubscriptionPaymentResult);

        self::assertSame($firstValue->paymentId, $secondValue->paymentId);
        self::assertTrue($secondValue->alreadyRecorded);
        self::assertCount(1, $this->payments->forClient(self::CLIENT));
        self::assertCount(1, $this->gatewayReferences->forPayment($firstValue->paymentId));
    }

    #[Test]
    public function twoDistinctChargesForTheSameSubscriptionEachGetTheirOwnPayment(): void
    {
        $first = $this->handler->handle(new RecordSubscriptionPaymentCommand(self::CLIENT, $this->subscriptionId, 'sub_charge_4', 'paid', 'paid'));
        $second = $this->handler->handle(new RecordSubscriptionPaymentCommand(self::CLIENT, $this->subscriptionId, 'sub_charge_5', 'paid', 'paid'));

        self::assertTrue($first->isOk());
        self::assertTrue($second->isOk());
        $firstValue = $first->value();
        $secondValue = $second->value();
        \assert($firstValue instanceof RecordSubscriptionPaymentResult);
        \assert($secondValue instanceof RecordSubscriptionPaymentResult);

        self::assertNotSame($firstValue->paymentId, $secondValue->paymentId);
        self::assertCount(2, $this->payments->forClient(self::CLIENT));
    }

    #[Test]
    public function anUnknownSubscriptionIsNotFound(): void
    {
        $result = $this->handler->handle(new RecordSubscriptionPaymentCommand(self::CLIENT, 999, 'sub_charge_6', 'paid', 'paid'));

        self::assertTrue($result->isErr());
        self::assertSame('subscription.not_found', $result->error()->code);
    }

    #[Test]
    public function anUnknownMappedStatusIsRejected(): void
    {
        $result = $this->handler->handle(new RecordSubscriptionPaymentCommand(self::CLIENT, $this->subscriptionId, 'sub_charge_7', 'weird', 'not_a_status'));

        self::assertTrue($result->isErr());
        self::assertSame('subscription_payment.unknown_status', $result->error()->code);
    }
}
