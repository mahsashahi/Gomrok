<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\PaymentsReturnAction;
use Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn\ConfirmCheckoutReturnHandler;
use Gomrok\Modules\Checkout\Application\ReconcileCheckoutStatus\ReconcileCheckoutStatusHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Payments\Application\ChangePaymentStatus\ChangePaymentStatusHandler;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentHandler;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\CreateSubscription\CreateSubscriptionHandler;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPaymentAttemptRepository;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryPricingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemorySubscriptionEventRepository;
use Gomrok\Tests\Support\InMemorySubscriptionPaymentLinkRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PaymentsReturnActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;
    private const SECRET = 'gomrokimo';

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private FakePaymentProviderPort $adapter;
    private PaymentsReturnAction $action;
    private InMemoryClientDirectory $clients;
    private int $attemptId;
    private string $token;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-13T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();

        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $payments = new InMemoryPaymentRepository();
        $pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();

        $this->adapter = new FakePaymentProviderPort();
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter);

        $createPayment = new CreatePaymentHandler(
            $this->attempts,
            $payments,
            new ResolveCheckoutPayableAmount($pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            $audit,
            $transactions,
            $clock,
        );
        $changePaymentStatus = new ChangePaymentStatusHandler($payments, new InMemoryPaymentAttemptRepository(), $audit, $transactions, $clock, new RecordingDomainEventDispatcher());
        $createSubscription = new CreateSubscriptionHandler(
            $this->attempts,
            $routingSnapshots,
            new ResolveCheckoutPayableAmount($pricingSnapshots, new InMemoryVoucherDecisionSnapshotRepository(), new InMemoryVoucherRedemptionRepository()),
            new PackagePurchaseCapabilityResolver(new InMemoryPackageRepository()),
            new InMemorySubscriptionRepository(),
            new InMemorySubscriptionEventRepository(),
            new InMemorySubscriptionPaymentLinkRepository(),
            $gatewayReferences,
            $audit,
            $transactions,
            $clock,
        );
        $reconcile = new ReconcileCheckoutStatusHandler($this->attempts, $routingSnapshots, $gatewayReferences, $adapterFactory, $createPayment, $changePaymentStatus, $createSubscription, $audit, $transactions, $clock);

        $this->clients = new InMemoryClientDirectory();
        $handler = new ConfirmCheckoutReturnHandler($this->attempts, $reconcile, $this->clients, self::SECRET);

        $this->action = new PaymentsReturnAction($handler, new JsonResponder());

        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::OneTimePayment, null, null, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);
        $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $this->now);
        $attempt->transitionTo(CheckoutAttemptStatus::ProviderCheckoutCreated, $this->now);
        $token = $attempt->issueReturnToken(self::SECRET);
        $this->attempts->save($attempt);
        $this->attemptId = $attemptId;
        $this->token = (string) $token;

        $routingSnapshots->save(new ProviderRoutingDecisionSnapshot(null, $attemptId, self::CLIENT, self::PROVIDER_ACCOUNT, 'card', 'one_time_payment', [], $this->now));
        $gatewayReferences->save(GatewayReference::forCheckoutAttempt(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $attemptId, $this->now));
        $price = new ResolvedPrice(self::PACKAGE, 'pro', 2900, '29.00', 'EUR', PriceSource::Baseline, 'default', true, 'Pro', null, false, 0);
        $pricingSnapshots->save(PricingDecisionSnapshot::of($attemptId, self::CLIENT, $price, $this->now));
    }

    #[Test]
    public function aSuccessfulPaymentRedirectsToTheClientsSuccessUrl(): void
    {
        $this->clients->setEndpoint(self::CLIENT, EndpointPurpose::CheckoutSuccess, 'https://televika.example/success');
        $this->adapter->createPaymentResultStatus('paid');

        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('GET', '/payments/return')
                ->withQueryParams(['return_token' => $this->token, 'outcome' => 'success']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('https://televika.example/success', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function noConfiguredEndpointFallsBackToAJsonBody(): void
    {
        $this->adapter->createPaymentResultStatus('paid');

        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('GET', '/payments/return')
                ->withQueryParams(['return_token' => $this->token]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{status: string} $body */
        self::assertSame('converted_to_payment', $body['status']);
    }

    #[Test]
    public function aMissingTokenIsAValidationError(): void
    {
        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('GET', '/payments/return'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('checkout_return.missing_token', $body['code']);
    }

    #[Test]
    public function aTamperedTokenIsRejected(): void
    {
        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('GET', '/payments/return')
                ->withQueryParams(['return_token' => $this->attemptId . '_deadbeef']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('checkout_return.invalid_token', $body['code']);
    }
}
