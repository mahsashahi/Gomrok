<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription\CreateCheckoutSubscriptionCommand;
use Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription\CreateCheckoutSubscriptionHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription\CreateCheckoutSubscriptionResult;
use Gomrok\Modules\Checkout\Application\CreateProviderSubscription\CreateProviderSubscriptionHandler;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingHandler;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderHandler;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Application\ResolveVisitorPriceListAssignment;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Providers\Application\Routing\ProviderRouter;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPriceListAssignmentRepository;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\InMemoryProviderRoutingDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\InMemoryVoucherCurrencyDiscountRepository;
use Gomrok\Tests\Support\InMemoryVoucherDecisionSnapshotRepository;
use Gomrok\Tests\Support\InMemoryVoucherEligibilityRuleRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The single `POST /api/v1/subscriptions` orchestration end to end, against
 * real (in-memory) sub-handlers — mirrors `CreateCheckoutPaymentHandlerTest`.
 */
final class CreateCheckoutSubscriptionHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private InMemoryCheckoutAttemptRepository $attempts;
    private FakePaymentProviderPort $adapter;
    private CreateCheckoutSubscriptionHandler $handler;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-14T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();
        $this->attempts = new InMemoryCheckoutAttemptRepository();

        $groups = new InMemoryPricingGroupRepository();
        $groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now));
        $defaultPrices = new InMemoryDefaultPackagePriceRepository();
        $defaultPrices->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));
        $packages = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro package');
        $priceResolver = new PriceResolver(
            $groups,
            new InMemoryPricingGroupPackageRepository(),
            $defaultPrices,
            new InMemoryClientExchangeRateRepository(),
            $packages,
            new PriceListResolver(new InMemoryPriceListRepository(), new InMemoryPriceListPackageRepository()),
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            new ResolveVisitorPriceListAssignment(new InMemoryPriceListAssignmentRepository(), new InMemoryPriceListRepository(), $clock),
            $clock,
        );
        $pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();

        $vouchers = new InMemoryVoucherRepository();
        $redemptions = new InMemoryVoucherRedemptionRepository();
        $voucherSnapshots = new InMemoryVoucherDecisionSnapshotRepository();
        $evaluator = new VoucherEligibilityEvaluator(
            new InMemoryVoucherEligibilityRuleRepository(),
            new InMemoryVoucherCurrencyDiscountRepository(),
            $redemptions,
        );
        $reserveVoucher = new ReserveVoucherRedemptionHandler(
            $vouchers,
            $redemptions,
            $evaluator,
            new VoucherDiscountCalculator(new InMemoryVoucherCurrencyDiscountRepository()),
            $audit,
            $transactions,
            $clock,
        );

        $providerGroups = new InMemoryProviderGroupRepository();
        $providerAccounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'stripe-test', 'stripe', countries: ['DE']);
        $providerGroup = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('default'), 'Default', true, null, null, $now);
        $providerGroup->setCountries([], $now);
        $providerGroup->setPurchaseTypes([PurchaseType::Subscription], $now);
        $providerGroup->setMethods([], $now);
        $providerGroup->setAccounts([ProviderGroupAccount::link(self::PROVIDER_ACCOUNT, 0)], $now);
        $providerGroups->save($providerGroup);
        $declarations = InMemoryProviderTypeDeclarations::withKnownProviders();
        $router = new ProviderRouter($providerGroups, $providerAccounts, $declarations, new NullLogger());
        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();

        $this->adapter = new FakePaymentProviderPort();
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter);

        $this->handler = new CreateCheckoutSubscriptionHandler(
            new CreateCheckoutAttemptHandler($this->attempts, new StubClientDirectory(self::CLIENT), $packages, new InMemoryReferenceCatalog(), $audit, $transactions, $clock),
            new ResolveCheckoutPricingHandler($this->attempts, $pricingSnapshots, $priceResolver, $audit, $transactions, $clock),
            new ReserveCheckoutVoucherHandler($this->attempts, $voucherSnapshots, $pricingSnapshots, $vouchers, $redemptions, $reserveVoucher, $audit, $transactions, $clock),
            new SelectCheckoutProviderHandler($this->attempts, $routingSnapshots, $router, $audit, $transactions, $clock),
            new CreateProviderSubscriptionHandler(
                $this->attempts,
                $routingSnapshots,
                new ResolveCheckoutPayableAmount($pricingSnapshots, $voucherSnapshots, $redemptions),
                $packages,
                $adapterFactory,
                new InMemoryGatewayReferenceRepository(),
                $audit,
                $transactions,
                $clock,
                'https://gomrok.example',
                'gomrokimo',
            ),
        );
    }

    #[Test]
    public function createsASubscriptionEndToEndAndReturnsTheRedirect(): void
    {
        $result = $this->handler->handle(new CreateCheckoutSubscriptionCommand(
            clientId: self::CLIENT,
            mode: 'test',
            attemptReference: 'order-1',
            packageId: self::PACKAGE,
            country: 'DE',
            currencyCode: 'EUR',
            clientUserRef: 'user-1',
            subscriptionInterval: 'monthly',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreateCheckoutSubscriptionResult);
        self::assertSame('provider_checkout_created', $value->status);
        self::assertSame('sub_1', $value->providerReference);

        $attempt = $this->attempts->findById($value->checkoutAttemptId);
        self::assertNotNull($attempt);
        self::assertSame(CheckoutAttemptStatus::ProviderCheckoutCreated, $attempt->status());
        self::assertSame(PurchaseType::Subscription, $attempt->purchaseType());
    }

    #[Test]
    public function passesTheClientUserRefThroughToTheAttempt(): void
    {
        $result = $this->handler->handle(new CreateCheckoutSubscriptionCommand(
            clientId: self::CLIENT,
            mode: 'test',
            attemptReference: 'order-2',
            packageId: self::PACKAGE,
            country: 'DE',
            currencyCode: 'EUR',
            clientUserRef: 'user-42',
            subscriptionInterval: 'yearly',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CreateCheckoutSubscriptionResult);

        $attempt = $this->attempts->findById($value->checkoutAttemptId);
        self::assertNotNull($attempt);
        self::assertSame('user-42', $attempt->clientUserRef());
    }

    #[Test]
    public function rejectsAnUnknownSubscriptionInterval(): void
    {
        $result = $this->handler->handle(new CreateCheckoutSubscriptionCommand(
            clientId: self::CLIENT,
            mode: 'test',
            attemptReference: 'order-3',
            packageId: self::PACKAGE,
            country: 'DE',
            currencyCode: 'EUR',
            clientUserRef: 'user-1',
            subscriptionInterval: 'biannually',
        ));

        self::assertTrue($result->isErr());
        self::assertSame('checkout_attempt.unknown_interval', $result->error()->code);
    }
}
