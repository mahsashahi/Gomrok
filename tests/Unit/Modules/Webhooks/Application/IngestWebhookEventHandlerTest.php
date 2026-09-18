<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Webhooks\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ParsedWebhookEvent;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Webhooks\Application\IngestWebhookEvent\IngestWebhookEventCommand;
use Gomrok\Modules\Webhooks\Application\IngestWebhookEvent\IngestWebhookEventHandler;
use Gomrok\Modules\Webhooks\Application\IngestWebhookEvent\IngestWebhookEventResult;
use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
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
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAccountCredentials;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IngestWebhookEventHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const CHECKOUT_ATTEMPT = 100;
    private const TOKEN = 'whk_test_token';

    private DateTimeImmutable $now;
    private InMemoryWebhookEventRepository $events;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemoryPaymentRepository $payments;
    private FakePaymentProviderPort $adapter;
    private IngestWebhookEventHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $this->events = new InMemoryWebhookEventRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->payments = new InMemoryPaymentRepository();

        $this->adapter = new FakePaymentProviderPort();
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter);

        $accounts = (new StubProviderAccountDirectory())
            ->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'stripe-main', 'stripe')
            ->withWebhookToken(self::PROVIDER_ACCOUNT, self::TOKEN);

        $credentials = (new StubProviderAccountCredentials())->withEndpointSigningSecret(self::PROVIDER_ACCOUNT, 'webhook', 'whsec_test');

        $recordTransaction = new RecordProviderTransactionHandler(
            $this->payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        $processor = new ProcessWebhookEventHandler(
            $this->events,
            $this->gatewayReferences,
            new InMemoryPaymentDirectory($this->payments),
            $adapterFactory,
            $recordTransaction,
            new NullErrorLogWriter(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );

        $this->handler = new IngestWebhookEventHandler(
            $accounts,
            $credentials,
            $adapterFactory,
            $this->events,
            $processor,
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );
    }

    #[Test]
    public function anUnknownTokenIsNotFound(): void
    {
        $result = $this->handler->handle(new IngestWebhookEventCommand('whk_does_not_exist', '{}', []));

        self::assertTrue($result->isErr());
        self::assertSame('webhook.unknown_token', $result->error()->code);
        self::assertSame(404, $result->error()->httpStatus());
    }

    #[Test]
    public function anInvalidSignatureIsRejectedAndStillStored(): void
    {
        $this->adapter->throwOnParseWebhook(new ProviderWebhookVerificationFailed('bad signature'));

        $result = $this->handler->handle(new IngestWebhookEventCommand(self::TOKEN, '{"id":"evt_bad"}', ['Stripe-Signature' => 'garbage']));

        self::assertTrue($result->isErr());
        self::assertSame('webhook.invalid_signature', $result->error()->code);
        self::assertSame(401, $result->error()->httpStatus());

        self::assertSame(1, $this->events->count());
    }

    #[Test]
    public function aValidWebhookIsStoredAndProcessedInline(): void
    {
        $paymentId = $this->seedPendingPayment();
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_1', $paymentId, $this->now));
        $this->adapter->webhookParseResult(new ParsedWebhookEvent('evt_1', 'payment_intent.succeeded', 'pi_1', 'paid', ['id' => 'evt_1']));

        $result = $this->handler->handle(new IngestWebhookEventCommand(self::TOKEN, '{"id":"evt_1"}', []));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof IngestWebhookEventResult);
        self::assertSame('processed', $value->outcome);

        $stored = $this->events->findById($value->webhookEventId);
        self::assertNotNull($stored);
        self::assertSame(WebhookEventStatus::Processed, $stored->status());
        self::assertSame('{"id":"evt_1"}', $stored->rawPayload());

        $payment = $this->payments->findById($paymentId);
        self::assertNotNull($payment);
        self::assertSame(PaymentStatus::Paid, $payment->status());
    }

    #[Test]
    public function anEventIsStoredEvenWhenInlineProcessingFails(): void
    {
        $this->adapter->webhookParseResult(new ParsedWebhookEvent('evt_2', 'payment_intent.succeeded', 'pi_missing', 'paid', ['id' => 'evt_2']));

        $result = $this->handler->handle(new IngestWebhookEventCommand(self::TOKEN, '{"id":"evt_2"}', []));

        // Q4: always ok() once stored + verified, regardless of the inline processing outcome.
        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof IngestWebhookEventResult);
        self::assertSame('retry_pending', $value->outcome);

        $stored = $this->events->findById($value->webhookEventId);
        self::assertNotNull($stored);
        self::assertSame(WebhookEventStatus::RetryPending, $stored->status());
        self::assertSame('{"id":"evt_2"}', $stored->rawPayload());
    }

    #[Test]
    public function aRedeliveredEventWithTheSameStatusIsIdempotent(): void
    {
        $paymentId = $this->seedPendingPayment();
        $this->gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::PaymentIntent, 'pi_3', $paymentId, $this->now));
        $this->adapter->webhookParseResult(new ParsedWebhookEvent('evt_3', 'payment_intent.succeeded', 'pi_3', 'paid', ['id' => 'evt_3']));

        $first = $this->handler->handle(new IngestWebhookEventCommand(self::TOKEN, '{"id":"evt_3"}', []));
        $second = $this->handler->handle(new IngestWebhookEventCommand(self::TOKEN, '{"id":"evt_3"}', []));

        self::assertTrue($first->isOk());
        self::assertTrue($second->isOk());
        $firstValue = $first->value();
        $secondValue = $second->value();
        \assert($firstValue instanceof IngestWebhookEventResult);
        \assert($secondValue instanceof IngestWebhookEventResult);

        self::assertSame('processed', $firstValue->outcome);
        self::assertSame('duplicate', $secondValue->outcome);
        self::assertSame($firstValue->webhookEventId, $secondValue->webhookEventId);

        // Only one WebhookEvent row for this (event id, status) pair — the
        // second delivery reused it rather than creating a duplicate row.
        self::assertSame(1, $this->events->count());
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
}
