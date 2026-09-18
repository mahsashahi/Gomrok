<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Webhooks\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment\RecordSubscriptionPaymentHandler;
use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Modules\Webhooks\Domain\WebhookEventStatus;
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

final class ProcessWebhookEventHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const CHECKOUT_ATTEMPT = 100;

    private DateTimeImmutable $now;
    private InMemoryWebhookEventRepository $events;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemoryPaymentRepository $payments;
    private ProcessWebhookEventHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
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
            new FrozenClock('2026-09-14T12:00:00+00:00'),
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
            new FrozenClock('2026-09-14T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        $this->handler = new ProcessWebhookEventHandler(
            $this->events,
            $this->gatewayReferences,
            new InMemoryPaymentDirectory($this->payments),
            $adapterFactory,
            $recordTransaction,
            $recordSubscriptionPayment,
            new NullErrorLogWriter(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );
    }

    #[Test]
    public function processesAnEventForAnExistingPayment(): void
    {
        $paymentId = $this->seedPendingPayment();
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_1', $paymentId, $this->now));

        $event = $this->seedEvent(eventId: 'evt_1', rawStatus: 'paid', providerReference: 'pi_1');

        $result = $this->handler->process($event);

        self::assertSame('processed', $result->outcome);
        $payment = $this->payments->findById($paymentId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Paid, $payment->status());
        self::assertSame(WebhookEventStatus::Processed, $event->status());
        self::assertNotNull($event->processedAt());
        self::assertSame($paymentId, $event->paymentId());
    }

    #[Test]
    public function leavesTheEventRetryableWhenNoGatewayReferenceMatches(): void
    {
        $event = $this->seedEvent(eventId: 'evt_2', rawStatus: 'paid', providerReference: 'pi_unknown');

        $result = $this->handler->process($event);

        self::assertSame('retry_pending', $result->outcome);
        self::assertSame(WebhookEventStatus::RetryPending, $event->status());
        self::assertSame(1, $event->attemptCount());
        self::assertSame('webhook.reference_not_found', $event->errorCode());
    }

    #[Test]
    public function leavesTheEventRetryableWhenTheCheckoutAttemptHasNoPaymentYet(): void
    {
        $this->gatewayReferences->save(GatewayReference::forCheckoutAttempt(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_1', self::CHECKOUT_ATTEMPT, $this->now));
        $event = $this->seedEvent(eventId: 'evt_3', rawStatus: 'paid', providerReference: 'cs_1');

        $result = $this->handler->process($event);

        self::assertSame('retry_pending', $result->outcome);
        self::assertSame('webhook.payment_not_found_yet', $event->errorCode());
        self::assertCount(0, $this->payments->forClient(self::CLIENT));
    }

    #[Test]
    public function retryingDoesNotCreateANewPayment(): void
    {
        $this->gatewayReferences->save(GatewayReference::forCheckoutAttempt(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_2', self::CHECKOUT_ATTEMPT, $this->now));
        $event = $this->seedEvent(eventId: 'evt_4', rawStatus: 'paid', providerReference: 'cs_2');

        $this->handler->process($event);
        $this->handler->process($event);
        $this->handler->process($event);

        self::assertCount(0, $this->payments->forClient(self::CLIENT));
        self::assertSame(3, $event->attemptCount());
    }

    #[Test]
    public function marksTheEventFailedOnceMaxAttemptsIsReached(): void
    {
        $event = $this->seedEvent(eventId: 'evt_5', rawStatus: 'paid', providerReference: 'pi_unknown');

        for ($i = 0; $i < ProcessWebhookEventHandler::MAX_ATTEMPTS - 1; ++$i) {
            $result = $this->handler->process($event);
            self::assertSame('retry_pending', $result->outcome);
        }

        $final = $this->handler->process($event);

        self::assertSame('failed', $final->outcome);
        self::assertSame(WebhookEventStatus::Failed, $event->status());
        self::assertSame(ProcessWebhookEventHandler::MAX_ATTEMPTS, $event->attemptCount());
    }

    #[Test]
    public function anIllegalTransitionFailsImmediatelyWithoutConsumingRetries(): void
    {
        $paymentId = $this->seedPaidPayment();
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_2', $paymentId, $this->now));

        // "requires_action" is not a legal next status from Paid.
        $event = $this->seedEvent(eventId: 'evt_6', rawStatus: 'requires_action', providerReference: 'pi_2');

        $result = $this->handler->process($event);

        self::assertSame('failed', $result->outcome);
        self::assertSame(1, $event->attemptCount());
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

    private function seedPendingPayment(): int
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        $payment->transitionTo(PaymentStatus::Pending, $this->now);
        $this->payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);

        return $id;
    }

    private function seedPaidPayment(): int
    {
        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT + 1, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        $payment->transitionTo(PaymentStatus::Pending, $this->now);
        $payment->transitionTo(PaymentStatus::Paid, $this->now);
        $this->payments->save($payment);
        $id = $payment->id();
        \assert($id !== null);

        return $id;
    }
}
