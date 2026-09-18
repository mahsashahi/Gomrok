<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\PaymentsCancelAction;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Payments\Application\CancelPayment\CancelPaymentHandler;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\ResolvePaymentActionContext;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
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

final class PaymentsCancelActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    #[Test]
    public function cancelsAPendingPayment(): void
    {
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $attempts = new InMemoryCheckoutAttemptRepository();
        $checkoutId = $this->seedCheckoutAttempt($attempts, $now);

        $payments = new InMemoryPaymentRepository();
        $payment = Payment::create(self::CLIENT, $checkoutId, 'user-1', self::PACKAGE, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $now);
        $payment->transitionTo(PaymentStatus::Pending, $now);
        $payments->save($payment);
        $paymentId = $payment->id();
        \assert($paymentId !== null);

        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $gatewayReferences->save(GatewayReference::forPayment(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $paymentId, $now));

        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $checkoutId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $now));

        $accounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'account-main', 'stripe');
        $adapter = new FakePaymentProviderPort();

        $context = new ResolvePaymentActionContext(
            $routingSnapshots,
            $accounts,
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $adapter),
            $gatewayReferences,
            new InMemorySubscriptionPaymentLinkRepository(),
            new InMemorySubscriptionRepository(),
        );

        $handler = new CancelPaymentHandler($payments, $context, new RecordProviderTransactionHandler(
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
        $action = new PaymentsCancelAction($clientContext, new InMemoryCheckoutAttemptDirectory($attempts), $handler, $idempotency, new JsonResponder());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('POST', "/api/v1/payments/{$checkoutId}/cancel"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $checkoutId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{status: string} $body */
        self::assertSame('canceled', $body['status']);
        self::assertSame('payment', $idempotency->targetType());
        self::assertSame($paymentId, $idempotency->targetId());
    }

    #[Test]
    public function anUnknownIdIs404(): void
    {
        $attempts = new InMemoryCheckoutAttemptRepository();
        $payments = new InMemoryPaymentRepository();
        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $accounts = new StubProviderAccountDirectory();

        $context = new ResolvePaymentActionContext(
            $routingSnapshots,
            $accounts,
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            new StubProviderAdapterFactory(),
            $gatewayReferences,
            new InMemorySubscriptionPaymentLinkRepository(),
            new InMemorySubscriptionRepository(),
        );
        $handler = new CancelPaymentHandler($payments, $context, new RecordProviderTransactionHandler(
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
        $action = new PaymentsCancelAction($clientContext, new InMemoryCheckoutAttemptDirectory($attempts), $handler, new IdempotencyContext(), new JsonResponder());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/payments/999/cancel'),
            (new ResponseFactory())->createResponse(),
            ['id' => '999'],
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function seedCheckoutAttempt(InMemoryCheckoutAttemptRepository $attempts, DateTimeImmutable $now): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $now);
        $attempts->save($attempt);
        $id = $attempt->id();
        \assert($id !== null);

        return $id;
    }
}
