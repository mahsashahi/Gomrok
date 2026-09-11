<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Checkout\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Application\ChangeCheckoutAttemptStatus\ChangeCheckoutAttemptStatusHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptCommand;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptResult;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherCommand;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherHandler;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherResult;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingCommand;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingResult;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderCommand;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderHandler;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderResult;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
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
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
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
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The end-to-end pre-payment flow: start -> resolve pricing -> (reserve a
 * voucher) -> select a provider, each step idempotent and each freezing its
 * own decision snapshot.
 */
final class CheckoutAttemptHandlersTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemoryPricingDecisionSnapshotRepository $pricingSnapshots;
    private InMemoryVoucherRepository $vouchers;
    private InMemoryVoucherRedemptionRepository $redemptions;
    private InMemoryVoucherDecisionSnapshotRepository $voucherSnapshots;
    private ReserveVoucherRedemptionHandler $reserveVoucher;
    private InMemoryProviderGroupRepository $providerGroups;
    private StubProviderAccountDirectory $providerAccounts;
    private ProviderRouter $router;
    private InMemoryProviderRoutingDecisionSnapshotRepository $routingSnapshots;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;
    private PriceResolver $priceResolver;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $this->clock = new FrozenClock('2026-09-11T12:00:00+00:00');
        $this->audit = new RecordingAuditLogWriter();
        $this->attempts = new InMemoryCheckoutAttemptRepository();

        $groups = new InMemoryPricingGroupRepository();
        $groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now));
        $defaultPrices = new InMemoryDefaultPackagePriceRepository();
        $defaultPrices->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));
        $packages = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro');
        $priceRules = new InMemoryPriceRuleRepository();
        $this->priceResolver = new PriceResolver(
            $groups,
            new InMemoryPricingGroupPackageRepository(),
            $defaultPrices,
            new InMemoryClientExchangeRateRepository(),
            $packages,
            new PriceListResolver(new InMemoryPriceListRepository(), new InMemoryPriceListPackageRepository()),
            new PriceRuleResolver($priceRules),
            $this->clock,
        );
        $this->pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();

        $this->vouchers = new InMemoryVoucherRepository();
        $this->redemptions = new InMemoryVoucherRedemptionRepository();
        $this->voucherSnapshots = new InMemoryVoucherDecisionSnapshotRepository();
        $evaluator = new VoucherEligibilityEvaluator(
            new InMemoryVoucherEligibilityRuleRepository(),
            new InMemoryVoucherCurrencyDiscountRepository(),
            $this->redemptions,
        );
        $this->reserveVoucher = new ReserveVoucherRedemptionHandler(
            $this->vouchers,
            $this->redemptions,
            $evaluator,
            new VoucherDiscountCalculator(new InMemoryVoucherCurrencyDiscountRepository()),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );

        $this->providerGroups = new InMemoryProviderGroupRepository();
        $this->providerAccounts = (new StubProviderAccountDirectory())->add(1, self::CLIENT, 'stripe-test', 'stripe', countries: ['DE']);
        $providerGroup = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('default'), 'Default', true, null, null, $now);
        $providerGroup->setCountries([], $now);
        $providerGroup->setPurchaseTypes([PurchaseType::OneTimePayment], $now);
        $providerGroup->setMethods([], $now);
        $providerGroup->setAccounts([ProviderGroupAccount::link(1, 0)], $now);
        $this->providerGroups->save($providerGroup);
        $this->router = new ProviderRouter($this->providerGroups, $this->providerAccounts, InMemoryProviderTypeDeclarations::withKnownProviders(), new NullLogger());
        $this->routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();
    }

    #[Test]
    public function startingTwiceWithTheSameAttemptReferenceIsIdempotent(): void
    {
        $handler = $this->createHandler();
        $command = new CreateCheckoutAttemptCommand(self::CLIENT, 'order-1', self::PACKAGE, 'DE', 'EUR', purchaseType: 'one_time_payment');

        $first = $handler->handle($command);
        $second = $handler->handle($command);

        self::assertTrue($first->isOk());
        $firstPayload = $first->value();
        $secondPayload = $second->value();
        self::assertInstanceOf(CreateCheckoutAttemptResult::class, $firstPayload);
        self::assertInstanceOf(CreateCheckoutAttemptResult::class, $secondPayload);
        self::assertSame($firstPayload->checkoutAttemptId, $secondPayload->checkoutAttemptId);
        self::assertSame('started', $firstPayload->status);
        self::assertContains('checkout_attempt.started', $this->audit->actions());
    }

    #[Test]
    public function resolvePricingFreezesASnapshotAndAdvancesStatus(): void
    {
        $attemptId = $this->start();

        $result = $this->pricingHandler()->handle(new ResolveCheckoutPricingCommand($attemptId, self::CLIENT));
        self::assertTrue($result->isOk());
        $payload = $result->value();
        self::assertInstanceOf(ResolveCheckoutPricingResult::class, $payload);
        self::assertSame(2900, $payload->amountMinor);
        self::assertSame('EUR', $payload->currencyCode);

        $attempt = $this->attempts->findById($attemptId);
        self::assertSame(CheckoutAttemptStatus::PricingResolved, $attempt?->status());

        // idempotent replay does not re-resolve or error
        $again = $this->pricingHandler()->handle(new ResolveCheckoutPricingCommand($attemptId, self::CLIENT));
        self::assertTrue($again->isOk());
        self::assertNotNull($this->pricingSnapshots->findByCheckoutAttemptId($attemptId));
    }

    #[Test]
    public function reserveVoucherRequiresPricingResolvedFirst(): void
    {
        $attemptId = $this->start();
        $this->seedVoucher();

        $result = $this->voucherHandler()->handle(new ReserveCheckoutVoucherCommand($attemptId, self::CLIENT, 'WELCOME10'));

        self::assertSame('checkout_attempt.pricing_not_resolved', $result->error()->code);
    }

    #[Test]
    public function reserveVoucherComputesTheDiscountAndAdvancesStatus(): void
    {
        $attemptId = $this->start();
        $this->pricingHandler()->handle(new ResolveCheckoutPricingCommand($attemptId, self::CLIENT));
        $this->seedVoucher();

        $result = $this->voucherHandler()->handle(new ReserveCheckoutVoucherCommand($attemptId, self::CLIENT, 'welcome10', clientUserRef: 'user-1'));

        self::assertTrue($result->isOk());
        $payload = $result->value();
        self::assertInstanceOf(ReserveCheckoutVoucherResult::class, $payload);
        self::assertSame(2610, $payload->payableMinor); // 2900 - 10%

        $attempt = $this->attempts->findById($attemptId);
        self::assertSame(CheckoutAttemptStatus::VoucherReserved, $attempt?->status());

        $snapshot = $this->voucherSnapshots->findByCheckoutAttemptId($attemptId);
        self::assertNotNull($snapshot);
        self::assertSame('WELCOME10', $snapshot->voucherCode);

        // the redemption reused the checkout attempt's own attempt_reference
        $redemption = $this->redemptions->findByAttemptReference($snapshot->voucherId, 'order-1');
        self::assertNotNull($redemption);
    }

    #[Test]
    public function selectProviderRequiresAPurchaseType(): void
    {
        $handler = $this->createHandler();
        $started = $handler->handle(new CreateCheckoutAttemptCommand(self::CLIENT, 'order-2', self::PACKAGE, 'DE', 'EUR'));
        $payload = $started->value();
        self::assertInstanceOf(CreateCheckoutAttemptResult::class, $payload);

        $result = $this->providerHandler()->handle(new SelectCheckoutProviderCommand($payload->checkoutAttemptId, self::CLIENT, 'test'));

        self::assertSame('checkout_attempt.purchase_type_required', $result->error()->code);
    }

    #[Test]
    public function selectProviderFreezesTheRoutingDecisionAndAdvancesStatus(): void
    {
        $attemptId = $this->start();
        $this->pricingHandler()->handle(new ResolveCheckoutPricingCommand($attemptId, self::CLIENT));

        $result = $this->providerHandler()->handle(new SelectCheckoutProviderCommand($attemptId, self::CLIENT, 'test'));

        self::assertTrue($result->isOk());
        $payload = $result->value();
        self::assertInstanceOf(SelectCheckoutProviderResult::class, $payload);
        self::assertSame(1, $payload->providerAccountId);

        $attempt = $this->attempts->findById($attemptId);
        self::assertSame(CheckoutAttemptStatus::ProviderSelected, $attempt?->status());
        self::assertNotNull($this->routingSnapshots->findByCheckoutAttemptId($attemptId));
    }

    #[Test]
    public function changeStatusDrivesTheExitStatusesAndAdmitsNoFurtherMoves(): void
    {
        $attemptId = $this->start();
        $handler = $this->statusHandler();

        self::assertTrue($handler->handle($attemptId, self::CLIENT, 'canceled')->isOk());
        self::assertSame(CheckoutAttemptStatus::Canceled, $this->attempts->findById($attemptId)?->status());
        self::assertSame('checkout_attempt.terminal', $handler->handle($attemptId, self::CLIENT, 'pricing_resolved')->error()->code);
        self::assertSame('checkout_attempt.not_found', $handler->handle(999_999, self::CLIENT, 'canceled')->error()->code);
    }

    private function start(): int
    {
        $result = $this->createHandler()->handle(new CreateCheckoutAttemptCommand(self::CLIENT, 'order-1', self::PACKAGE, 'DE', 'EUR', purchaseType: 'one_time_payment'));
        $payload = $result->value();
        self::assertInstanceOf(CreateCheckoutAttemptResult::class, $payload);

        return $payload->checkoutAttemptId;
    }

    private function seedVoucher(): void
    {
        $voucher = Voucher::create(self::CLIENT, 'WELCOME10', 'Welcome', null, null, null, false, null, null, DefaultDiscountType::Percentage, 1000, $this->clock->now());
        $this->vouchers->save($voucher);
    }

    private function createHandler(): CreateCheckoutAttemptHandler
    {
        return new CreateCheckoutAttemptHandler(
            $this->attempts,
            new StubClientDirectory(self::CLIENT),
            (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro'),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }

    private function pricingHandler(): ResolveCheckoutPricingHandler
    {
        return new ResolveCheckoutPricingHandler($this->attempts, $this->pricingSnapshots, $this->priceResolver, $this->audit, new SynchronousTransactions(), $this->clock);
    }

    private function voucherHandler(): ReserveCheckoutVoucherHandler
    {
        return new ReserveCheckoutVoucherHandler(
            $this->attempts,
            $this->voucherSnapshots,
            $this->pricingSnapshots,
            $this->vouchers,
            $this->redemptions,
            $this->reserveVoucher,
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }

    private function providerHandler(): SelectCheckoutProviderHandler
    {
        return new SelectCheckoutProviderHandler($this->attempts, $this->routingSnapshots, $this->router, $this->audit, new SynchronousTransactions(), $this->clock);
    }

    private function statusHandler(): ChangeCheckoutAttemptStatusHandler
    {
        return new ChangeCheckoutAttemptStatusHandler($this->attempts, $this->audit, new SynchronousTransactions(), $this->clock);
    }
}
