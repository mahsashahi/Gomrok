<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Jobs;

use DateTimeImmutable;
use Gomrok\Jobs\RetryPendingWebhookEvents;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Modules\Webhooks\Domain\WebhookEventStatus;
use Gomrok\Shared\Infrastructure\Persistence\NullErrorLogWriter;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentDirectory;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\InMemoryWebhookEventRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RetryPendingWebhookEventsTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const CHECKOUT_ATTEMPT = 100;

    #[Test]
    public function processesARetryPendingEventAndUpdatesThePayment(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $events = new InMemoryWebhookEventRepository();
        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $payments = new InMemoryPaymentRepository();

        $payment = Payment::create(self::CLIENT, self::CHECKOUT_ATTEMPT, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $now);
        $payment->transitionTo(PaymentStatus::Pending, $now);
        $payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        // First attempt happened before the GatewayReference existed —
        // simulating the webhook arriving moments before Gomrok's own
        // checkout-creation flow recorded the reference.
        $event = WebhookEvent::receive(self::CLIENT, self::PROVIDER_ACCOUNT, 'stripe', 'evt_1', 'payment_intent.succeeded', 'paid', 'pi_1', '{"id":"evt_1"}', [], $now);
        $events->save($event);
        $event->markRetryPending($now, 'webhook.reference_not_found', 'not found yet');
        $events->save($event);

        // Now the reference exists.
        $gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_1', $paymentId, $now));

        $adapter = new FakePaymentProviderPort();
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $adapter);
        $recordTransaction = new RecordProviderTransactionHandler(
            $payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );
        $processor = new ProcessWebhookEventHandler(
            $events,
            $gatewayReferences,
            new InMemoryPaymentDirectory($payments),
            $adapterFactory,
            $recordTransaction,
            new NullErrorLogWriter(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );

        $job = new RetryPendingWebhookEvents($events, $processor, new NullLogger());
        $summary = $job();

        self::assertSame(1, $summary['attempted']);
        self::assertSame(1, $summary['processed']);
        self::assertSame(0, $summary['retry_pending']);
        self::assertSame(0, $summary['failed']);

        self::assertSame(WebhookEventStatus::Processed, $event->status());
        $reloaded = $payments->findById($paymentId);
        self::assertNotNull($reloaded);
        self::assertSame(PaymentStatus::Paid, $reloaded->status());
    }

    #[Test]
    public function skipsEventsThatAreAlreadyProcessedOrTerminallyFailed(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $events = new InMemoryWebhookEventRepository();

        $processed = WebhookEvent::receive(self::CLIENT, self::PROVIDER_ACCOUNT, 'stripe', 'evt_done', 't', 'paid', 'pi_done', '{}', [], $now);
        $events->save($processed);
        $processed->markProcessed($now, 999);
        $events->save($processed);

        $failed = WebhookEvent::receive(self::CLIENT, self::PROVIDER_ACCOUNT, 'stripe', 'evt_failed', 't', 'paid', 'pi_failed', '{}', [], $now);
        $events->save($failed);
        $failed->markFailed($now, 'webhook.unparsed_event', 'no good');
        $events->save($failed);

        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, new FakePaymentProviderPort());
        $processor = new ProcessWebhookEventHandler(
            $events,
            new InMemoryGatewayReferenceRepository(),
            new InMemoryPaymentDirectory(new InMemoryPaymentRepository()),
            $adapterFactory,
            new RecordProviderTransactionHandler(
                new InMemoryPaymentRepository(),
                new InMemoryPaymentAttemptRepository(),
                new InMemoryProviderTransactionRepository(),
                new RecordingAuditLogWriter(),
                new SynchronousTransactions(),
                new FrozenClock('2026-09-14T12:00:00+00:00'),
            ),
            new NullErrorLogWriter(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );

        $job = new RetryPendingWebhookEvents($events, $processor, new NullLogger());
        $summary = $job();

        self::assertSame(0, $summary['attempted']);
    }
}
