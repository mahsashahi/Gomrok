<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\PaymentsCreateAction;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutPayment\CreateCheckoutPaymentHandler;
use Gomrok\Modules\Checkout\Application\CreateProviderCheckout\CreateProviderCheckoutHandler;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPayableAmount;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingHandler;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderHandler;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Application\ResolveVisitorPriceListAssignment;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Providers\Application\Adapter\ProviderPaymentResult;
use Gomrok\Modules\Providers\Application\Routing\ProviderRouter;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
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
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PaymentsCreateActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private StubPackageDirectory $packages;
    private InMemoryPricingDecisionSnapshotRepository $pricingSnapshots;
    private PaymentsCreateAction $action;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-13T12:00:00+00:00');
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();
        $attempts = new InMemoryCheckoutAttemptRepository();

        $groups = new InMemoryPricingGroupRepository();
        $groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now));
        $defaultPrices = new InMemoryDefaultPackagePriceRepository();
        $defaultPrices->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));
        $this->packages = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro package');
        $priceResolver = new PriceResolver(
            $groups,
            new InMemoryPricingGroupPackageRepository(),
            $defaultPrices,
            new InMemoryClientExchangeRateRepository(),
            $this->packages,
            new PriceListResolver(new InMemoryPriceListRepository(), new InMemoryPriceListPackageRepository()),
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            new ResolveVisitorPriceListAssignment(new InMemoryPriceListAssignmentRepository(), new InMemoryPriceListRepository(), $clock),
            $clock,
        );
        $pricingSnapshots = new InMemoryPricingDecisionSnapshotRepository();
        $this->pricingSnapshots = $pricingSnapshots;

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
        $providerGroup->setPurchaseTypes([PurchaseType::OneTimePayment], $now);
        $providerGroup->setMethods([], $now);
        $providerGroup->setAccounts([ProviderGroupAccount::link(self::PROVIDER_ACCOUNT, 0)], $now);
        $providerGroups->save($providerGroup);
        $router = new ProviderRouter($providerGroups, $providerAccounts, InMemoryProviderTypeDeclarations::withKnownProviders(), new NullLogger());
        $routingSnapshots = new InMemoryProviderRoutingDecisionSnapshotRepository();

        $adapter = new FakePaymentProviderPort(new ProviderPaymentResult('cs_test_1', 'https://checkout.stripe.com/cs_test_1', 'open'));
        $adapterFactory = (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $adapter);

        $handler = new CreateCheckoutPaymentHandler(
            new CreateCheckoutAttemptHandler($attempts, new StubClientDirectory(self::CLIENT), $this->packages, new InMemoryReferenceCatalog(), $audit, $transactions, $clock),
            new ResolveCheckoutPricingHandler($attempts, $pricingSnapshots, $priceResolver, $audit, $transactions, $clock),
            new ReserveCheckoutVoucherHandler($attempts, $voucherSnapshots, $pricingSnapshots, $vouchers, $redemptions, $reserveVoucher, $audit, $transactions, $clock),
            new SelectCheckoutProviderHandler($attempts, $routingSnapshots, $router, $audit, $transactions, $clock),
            new CreateProviderCheckoutHandler(
                $attempts,
                $routingSnapshots,
                new ResolveCheckoutPayableAmount($pricingSnapshots, $voucherSnapshots, $redemptions),
                $this->packages,
                $adapterFactory,
                new InMemoryGatewayReferenceRepository(),
                $audit,
                $transactions,
                $clock,
                'https://gomrok.example',
                'gomrokimo',
            ),
        );

        $context = new ClientContext();
        $context->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));

        $this->action = new PaymentsCreateAction($context, $this->packages, $handler, new IdempotencyContext(), new JsonResponder());
    }

    #[Test]
    public function createsAPaymentAndReturnsTheRedirect(): void
    {
        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/payments')
                ->withParsedBody(['attempt_reference' => 'order-1', 'package' => 'pro', 'country' => 'DE', 'currency' => 'EUR']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{checkout_attempt_id: int, status: string, redirect_url: string} $body */
        self::assertSame('provider_checkout_created', $body['status']);
        self::assertSame('https://checkout.stripe.com/cs_test_1', $body['redirect_url']);
    }

    #[Test]
    public function aClientSuppliedPriceFieldIsIgnoredAndTheServerResolvedPriceIsUsed(): void
    {
        // Phase 30A Q4 security pass: CLAUDE.md — "Gomrok must calculate and
        // validate the final price itself" / "Client-supplied prices must not
        // be trusted as the source of truth." This action never even reads
        // an amount/price field from the request body (see PaymentsCreateAction
        // — only attempt_reference/package/country/currency/etc. are parsed);
        // this test proves a malicious payload smuggling one in has no effect.
        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/payments')
                ->withParsedBody([
                    'attempt_reference' => 'order-manipulated',
                    'package' => 'pro',
                    'country' => 'DE',
                    'currency' => 'EUR',
                    // Attacker-supplied fields attempting to override the price.
                    'amount_minor' => 1,
                    'price' => 1,
                    'final_price' => 1,
                    'amount' => 1,
                ]),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{checkout_attempt_id: int} $body */
        $snapshot = $this->pricingSnapshots->findByCheckoutAttemptId($body['checkout_attempt_id']);
        self::assertNotNull($snapshot);
        self::assertSame(2900, $snapshot->amountMinor, 'the server-resolved default package price, unaffected by the injected fields');
    }

    #[Test]
    public function missingFieldsAreAValidationError(): void
    {
        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/payments')
                ->withParsedBody(['package' => 'pro']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('payments.missing_fields', $body['code']);
    }

    #[Test]
    public function aPackageBelongingToAnotherClientIsNotFoundEvenByNumericId(): void
    {
        // Phase 30A Q4 security pass — multi-client isolation. StubPackageDirectory::findById()
        // (like the real PdoPackageDirectory) resolves by numeric id alone, not scoped to a
        // client — PaymentsCreateAction itself must enforce ownership, which this proves.
        $this->packages->add(99, self::CLIENT + 1, 'other-clients-package');

        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/payments')
                ->withParsedBody(['attempt_reference' => 'order-3', 'package' => '99', 'country' => 'DE', 'currency' => 'EUR']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('package.not_found', $body['code']);
    }

    #[Test]
    public function anUnknownPackageIs404(): void
    {
        $response = ($this->action)(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/payments')
                ->withParsedBody(['attempt_reference' => 'order-2', 'package' => 'ghost', 'country' => 'DE', 'currency' => 'EUR']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('package.not_found', $body['code']);
    }
}
