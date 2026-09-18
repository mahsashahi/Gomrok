<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\PaymentsCaptureAction;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Payments\Application\CapturePayment\CapturePaymentHandler;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\ResolvePaymentActionContext;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptDirectory;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderTransactionRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\InMemorySubscriptionPaymentLinkRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PaymentsCaptureActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    #[Test]
    public function capturesAnAuthorizedPayment(): void
    {
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $attempts = new InMemoryCheckoutAttemptRepository();
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $now);
        $attempts->save($attempt);
        $checkoutId = $attempt->id();
        \assert($checkoutId !== null);

        $payments = new InMemoryPaymentRepository();
        $payment = Payment::create(self::CLIENT, $checkoutId, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $now);
        $payment->transitionTo(PaymentStatus::Pending, $now);
        $payment->transitionTo(PaymentStatus::Authorized, $now);
        $payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $paymentId, $now));

        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $checkoutId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $now));

        $accounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'account-main', 'stripe');
        $adapter = new FakePaymentProviderPort();
        $adapter->captureResult(new ProviderPaymentResult('pi_1', '', 'succeeded'));

        $context = new ResolvePaymentActionContext(
            $routingSnapshots,
            $accounts,
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $adapter),
            $gatewayReferences,
            new InMemorySubscriptionPaymentLinkRepository(),
            new InMemorySubscriptionRepository(),
        );

        $handler = new CapturePaymentHandler($payments, $context, new RecordProviderTransactionHandler(
            $payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        ));

        $clientContext = new ClientContext();
        $clientContext->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));
        $idempotency = new IdempotencyContext();
        $action = new PaymentsCaptureAction($clientContext, new InMemoryCheckoutAttemptDirectory($attempts), $handler, $idempotency, new JsonResponder());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('POST', "/api/v1/payments/{$checkoutId}/capture"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $checkoutId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{status: string, provider_reference: string} $body */
        self::assertSame('paid', $body['status']);
        self::assertSame('pi_1', $body['provider_reference']);
        self::assertSame('payment', $idempotency->targetType());
    }

    #[Test]
    public function anUnknownIdIs404(): void
    {
        $attempts = new InMemoryCheckoutAttemptRepository();
        $payments = new InMemoryPaymentRepository();
        $context = new ResolvePaymentActionContext(
            new InMemoryProviderRoutingDecisionSnapshotRepository(),
            new StubProviderAccountDirectory(),
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            new StubProviderAdapterFactory(),
            new InMemoryGatewayReferenceRepository(),
            new InMemorySubscriptionPaymentLinkRepository(),
            new InMemorySubscriptionRepository(),
        );
        $handler = new CapturePaymentHandler($payments, $context, new RecordProviderTransactionHandler(
            $payments,
            new InMemoryPaymentAttemptRepository(),
            new InMemoryProviderTransactionRepository(),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-13T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        ));

        $clientContext = new ClientContext();
        $clientContext->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));
        $action = new PaymentsCaptureAction($clientContext, new InMemoryCheckoutAttemptDirectory($attempts), $handler, new IdempotencyContext(), new JsonResponder());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/payments/999/capture'),
            (new ResponseFactory())->createResponse(),
            ['id' => '999'],
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
