<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\PaymentsStatusAction;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Payments\Application\ChangePaymentStatus\ChangePaymentStatusHandler;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptDirectory;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryPricingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PaymentsStatusActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    #[Test]
    public function activelyRechecksAndReportsTheConfirmedStatus(): void
    {
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-13T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();

        $attempts = new InMemoryCheckoutAttemptRepository();
        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $payments = new InMemoryPaymentRepository();
        $pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();

        $adapter = new FakePaymentProviderPort();
        $adapter->createPaymentResultStatus('paid');
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $adapter);

        $createPayment = new CreatePaymentHandler(
            $attempts,
            $payments,
            new ResolveCheckoutPayableAmount($pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            $audit,
            $transactions,
            $clock,
        );
        $changePaymentStatus = new ChangePaymentStatusHandler($payments, $audit, $transactions, $clock);
        $reconcile = new ReconcileCheckoutStatusHandler($attempts, $routingSnapshots, $gatewayReferences, $adapterFactory, $createPayment, $changePaymentStatus, $audit, $transactions, $clock);

        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $now);
        $attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderCheckoutCreated, $now);
        $attempts->save($attempt);

        $routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $now));
        $gatewayReferences->save(GatewayReference::forCheckoutAttempt(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $attemptId, $now));
        $price = new ResolvedPrice(self::PACKAGE, 'pro', 2900, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $pricingSnapshots->save(PricingDecisionSnapshot::of($attemptId, self::CLIENT, $price, $now));

        $context = new ClientContext();
        $context->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));
        $action = new PaymentsStatusAction($context, new InMemoryCheckoutAttemptDirectory($attempts), $reconcile, new JsonResponder());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', "/api/v1/payments/{$attemptId}/status"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $attemptId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{status: string} $body */
        self::assertSame('converted_to_payment', $body['status']);
        self::assertNotNull($payments->findByCheckoutAttemptId($attemptId));
    }

    #[Test]
    public function anUnknownIdIs404(): void
    {
        $attempts = new InMemoryCheckoutAttemptRepository();
        $clock = new FrozenClock('2026-09-13T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();
        $payments = new InMemoryPaymentRepository();
        $createPayment = new CreatePaymentHandler(
            $attempts,
            $payments,
            new ResolveCheckoutPayableAmount(new InMemoryPricingDecisionSnapshotRepository(), new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            $audit,
            $transactions,
            $clock,
        );
        $reconcile = new ReconcileCheckoutStatusHandler(
            $attempts,
            new InMemoryProviderRoutingDecisionSnapshotRepository(),
            new InMemoryGatewayReferenceRepository(),
            new StubProviderAdapterFactory(),
            $createPayment,
            new ChangePaymentStatusHandler($payments, $audit, $transactions, $clock),
            $audit,
            $transactions,
            $clock,
        );

        $context = new ClientContext();
        $context->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));
        $action = new PaymentsStatusAction($context, new InMemoryCheckoutAttemptDirectory($attempts), $reconcile, new JsonResponder());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/payments/999/status'),
            (new ResponseFactory())->createResponse(),
            ['id' => '999'],
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
