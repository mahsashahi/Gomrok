<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Webhooks\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentHandler;
use Gomrok\Modules\Webhooks\Application\Jobs\WebhookRetryScanHandler;
use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Shared\Infrastructure\Persistence\NullErrorLogWriter;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentDirectory;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\InMemorySubscriptionEventRepository;
use Gomrok\Tests\Support\InMemorySubscriptionPaymentLinkRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\InMemoryWebhookEventRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookRetryScanHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const CHECKOUT_ATTEMPT = 100;

    private DateTimeImmutable $now;
    private InMemoryWebhookEventRepository $events;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemoryPaymentRepository $payments;
    private WebhookRetryScanHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $this->events = new InMemoryWebhookEventRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->payments = new InMemoryPaymentRepository();

        $adapter = new FakePaymentProviderPort();
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $adapter);

        $recordTransaction = new RecordProviderTransactionHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-17T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        $recordSubscriptionPayment = new RecordSubscriptionPaymentHandler(
            new InMemorySubscriptionRepository(),
            new InMemoryCheckoutAttemptRepository(),
            $this->payments,
            new InMemorySubscriptionPaymentLinkRepository(),
            new InMemorySubscriptionEventRepository(),
            $this->gatewayReferences,
            $recordTransaction,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-17T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        $processor = new ProcessWebhookEventHandler(
            $this->events,
            $this->gatewayReferences,
            new InMemoryPaymentDirectory($this->payments),
            $adapterFactory,
            $recordTransaction,
            $recordSubscriptionPayment,
            new NullErrorLogWriter(),
            new FrozenClock('2026-09-17T12:00:00+00:00'),
        );

        $this->handler = new WebhookRetryScanHandler($this->events, $processor);
    }

    private function seedEvent(string $eventId, string $rawStatus, string $providerReference): WebhookEvent
    {
        $event = WebhookEvent::receive(
            self::CLIENT,
            self::PROVIDER_ACCOUNT,
            'stripe',
            $eventId,
            'payment_intent.succeeded',
            $rawStatus,
            $providerReference,
            '{"id":"' . $eventId . '"}',
            [],
            $this->now,
        );
        $this->events->save($event);

        return $event;
    }

    #[Test]
    public function exposesItsTypeAndRecurrence(): void
    {
        self::assertSame('webhook_retry_scan', $this->handler->type());
        self::assertSame(5, $this->handler->recurrenceIntervalMinutes());
    }

    #[Test]
    public function processesEveryRetryableEventAndTalliesEachOutcome(): void
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        $payment->transitionTo(PaymentStatus::Pending, $this->now);
        $this->payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_1', $paymentId, $this->now));

        $this->seedEvent('evt_processed', 'paid', 'pi_1');
        $this->seedEvent('evt_retry', 'paid', 'pi_unknown');

        $job = Job::schedule('webhook_retry_scan', null, $this->now, $this->now);
        $result = $this->handler->handle($job);

        self::assertTrue($result->success);
        self::assertSame(['attempted' => 2, 'processed' => 1, 'retry_pending' => 1, 'failed' => 0], $result->summary);
    }

    #[Test]
    public function reportsAllZeroesWhenNothingIsRetryable(): void
    {
        $job = Job::schedule('webhook_retry_scan', null, $this->now, $this->now);
        $result = $this->handler->handle($job);

        self::assertSame(['attempted' => 0, 'processed' => 0, 'retry_pending' => 0, 'failed' => 0], $result->summary);
    }
}
