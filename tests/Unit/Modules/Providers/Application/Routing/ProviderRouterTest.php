<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Application\Routing;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Application\Routing\ProviderRouter;
use Gomrok\Modules\Providers\Application\Routing\RejectionReason;
use Gomrok\Modules\Providers\Application\Routing\RoutingDecision;
use Gomrok\Modules\Providers\Application\Routing\RoutingRequest;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\ErrorType;
use Gomrok\Tests\Support\InMemoryProviderGroupRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProviderRouterTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryProviderGroupRepository $groups;
    private StubProviderAccountDirectory $accounts;
    private ProviderRouter $router;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-09T12:00:00+00:00');
        $this->groups = new InMemoryProviderGroupRepository();
        $this->accounts = new StubProviderAccountDirectory();
        $this->router = new ProviderRouter(
            $this->groups,
            $this->accounts,
            InMemoryProviderTypeDeclarations::withKnownProviders(),
            new NullLogger(),
        );
    }

    #[Test]
    public function turkeyResolvesToZiraatForAOneTimePayment(): void
    {
        $this->accounts->add(1, self::CLIENT, 'ziraat-tr', 'ziraat', countries: ['TR']);
        $this->group('turkey', false, ['TR'], [PurchaseType::OneTimePayment], [], [ProviderGroupAccount::link(1, 0)]);

        $result = $this->router->route($this->request('TR', 'TRY', PurchaseType::OneTimePayment));

        self::assertTrue($result->isOk());
        $decision = $result->value();
        self::assertInstanceOf(RoutingDecision::class, $decision);
        self::assertSame('ziraat-tr', $decision->chosen()->slug);
        self::assertSame('turkey', $decision->groupSlug);
    }

    #[Test]
    public function turkeySubscriptionIsRejectedNotDowngraded(): void
    {
        $this->accounts->add(1, self::CLIENT, 'ziraat-tr', 'ziraat', countries: ['TR']);
        $this->group('turkey', false, ['TR'], [PurchaseType::OneTimePayment], [], [ProviderGroupAccount::link(1, 0)]);

        $result = $this->router->route($this->request('TR', 'TRY', PurchaseType::Subscription));

        self::assertTrue($result->isErr());
        self::assertSame('provider_routing.purchase_type_not_enabled', $result->error()->code);
        self::assertSame(ErrorType::RuleViolation, $result->error()->type);
    }

    #[Test]
    public function germanyOffersMollieForCardAndPaypal(): void
    {
        $this->accounts->add(2, self::CLIENT, 'mollie-de', 'mollie', countries: ['DE'], methods: ['card', 'paypal']);
        $this->group('germany', false, ['DE'], [PurchaseType::OneTimePayment], [PaymentMethod::Card, PaymentMethod::PayPal], [ProviderGroupAccount::link(2, 0)]);

        $card = $this->router->route($this->request('DE', 'EUR', PurchaseType::OneTimePayment, PaymentMethod::Card));
        $paypal = $this->router->route($this->request('DE', 'EUR', PurchaseType::OneTimePayment, PaymentMethod::PayPal));

        self::assertTrue($card->isOk());
        self::assertTrue($paypal->isOk());
        $cardDecision = $card->value();
        self::assertInstanceOf(RoutingDecision::class, $cardDecision);
        self::assertSame('mollie-de', $cardDecision->chosen()->slug);
    }

    #[Test]
    public function germanyRejectsAMethodTheGroupDoesNotEnable(): void
    {
        $this->accounts->add(2, self::CLIENT, 'mollie-de', 'mollie', countries: ['DE'], methods: ['card', 'paypal']);
        $this->group('germany', false, ['DE'], [PurchaseType::OneTimePayment], [PaymentMethod::Card], [ProviderGroupAccount::link(2, 0)]);

        $result = $this->router->route($this->request('DE', 'EUR', PurchaseType::OneTimePayment, PaymentMethod::PayPal));

        self::assertTrue($result->isErr());
        self::assertSame('provider_routing.method_not_enabled', $result->error()->code);
    }

    #[Test]
    public function netherlandsResolvesToPaypalOnly(): void
    {
        $this->accounts->add(3, self::CLIENT, 'paypal-nl', 'paypal', countries: ['NL']);
        $this->group('netherlands', false, ['NL'], [PurchaseType::OneTimePayment], [], [ProviderGroupAccount::link(3, 0)]);

        $result = $this->router->route($this->request('NL', 'EUR', PurchaseType::OneTimePayment));

        self::assertTrue($result->isOk());
        $decision = $result->value();
        self::assertInstanceOf(RoutingDecision::class, $decision);
        self::assertSame('paypal', $decision->chosen()->providerTypeCode);
    }

    #[Test]
    public function unmatchedCountryFallsThroughToTheDefaultGroup(): void
    {
        $this->accounts->add(9, self::CLIENT, 'stripe-default', 'stripe');
        $this->group('default', true, [], [PurchaseType::OneTimePayment, PurchaseType::Subscription], [], [ProviderGroupAccount::link(9, 0)]);

        $result = $this->router->route($this->request('FR', 'EUR', PurchaseType::Subscription));

        self::assertTrue($result->isOk());
        $decision = $result->value();
        self::assertInstanceOf(RoutingDecision::class, $decision);
        self::assertTrue($decision->groupIsDefault);
        self::assertSame('stripe-default', $decision->chosen()->slug);
    }

    #[Test]
    public function noGroupAndNoDefaultIsANotFound(): void
    {
        $result = $this->router->route($this->request('FR', 'EUR', PurchaseType::OneTimePayment));

        self::assertTrue($result->isErr());
        self::assertSame('provider_routing.no_group_for_market', $result->error()->code);
    }

    #[Test]
    public function aTestRequestNeverResolvesALiveAccount(): void
    {
        $this->accounts->add(1, self::CLIENT, 'stripe-live', 'stripe', mode: 'live');
        $this->group('default', true, [], [PurchaseType::OneTimePayment], [], [ProviderGroupAccount::link(1, 0)]);

        $result = $this->router->route($this->request('DE', 'EUR', PurchaseType::OneTimePayment));

        self::assertTrue($result->isErr());
        self::assertSame('provider_routing.no_provider_for_market', $result->error()->code);
    }

    #[Test]
    public function aDisabledAccountIsSkippedAndTheNextCandidateWins(): void
    {
        $this->accounts->add(1, self::CLIENT, 'mollie-primary', 'mollie', status: 'disabled', countries: ['DE']);
        $this->accounts->add(2, self::CLIENT, 'stripe-backup', 'stripe', countries: ['DE']);
        $this->group('germany', false, ['DE'], [PurchaseType::OneTimePayment], [], [
            ProviderGroupAccount::link(1, 0),
            ProviderGroupAccount::link(2, 1),
        ]);

        $result = $this->router->route($this->request('DE', 'EUR', PurchaseType::OneTimePayment));

        self::assertTrue($result->isOk());
        $decision = $result->value();
        self::assertInstanceOf(RoutingDecision::class, $decision);
        self::assertSame('stripe-backup', $decision->chosen()->slug);
        self::assertCount(1, $decision->rejections);
        self::assertSame(RejectionReason::AccountDisabled, $decision->rejections[0]->reason);
    }

    #[Test]
    public function currencyMismatchAgainstAPinnedGroupCurrencyIsRejected(): void
    {
        $this->accounts->add(1, self::CLIENT, 'ziraat-tr', 'ziraat', countries: ['TR']);
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of('turkey'), 'Turkey', false, null, 'TRY', $this->now);
        $group->setCountries(['TR'], $this->now);
        $group->setPurchaseTypes([PurchaseType::OneTimePayment], $this->now);
        $group->setAccounts([ProviderGroupAccount::link(1, 0)], $this->now);
        $this->groups->save($group);

        $result = $this->router->route($this->request('TR', 'EUR', PurchaseType::OneTimePayment));

        self::assertTrue($result->isErr());
        self::assertSame('provider_routing.currency_not_supported', $result->error()->code);
    }

    /**
     * @param list<ProviderGroupAccount> $accounts
     * @param list<PurchaseType>         $purchaseTypes
     * @param list<PaymentMethod>        $methods
     * @param list<string>               $countries
     */
    private function group(string $slug, bool $isDefault, array $countries, array $purchaseTypes, array $methods, array $accounts): void
    {
        $group = ProviderGroup::define(self::CLIENT, ProviderGroupSlug::of($slug), ucfirst($slug), $isDefault, null, null, $this->now);
        $group->setCountries($countries, $this->now);
        $group->setPurchaseTypes($purchaseTypes, $this->now);
        $group->setMethods($methods, $this->now);
        $group->setAccounts($accounts, $this->now);
        $this->groups->save($group);
    }

    private function request(
        string $country,
        string $currency,
        PurchaseType $purchaseType,
        ?PaymentMethod $method = null,
    ): RoutingRequest {
        return new RoutingRequest(self::CLIENT, $country, $currency, $purchaseType, ProviderAccountMode::Test, $method);
    }
}
