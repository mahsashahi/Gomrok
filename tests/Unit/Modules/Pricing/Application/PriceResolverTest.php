<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRate;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceRule;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\StubPackageDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceResolverTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private InMemoryPricingGroupRepository $groups;
    private InMemoryPricingGroupPackageRepository $rows;
    private InMemoryDefaultPackagePriceRepository $defaults;
    private InMemoryClientExchangeRateRepository $rates;
    private InMemoryPriceRuleRepository $priceRules;
    private InMemoryPriceListRepository $priceLists;
    private InMemoryPriceListPackageRepository $listPackages;
    private StubPackageDirectory $packages;
    private PriceResolver $resolver;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
        $this->groups = new InMemoryPricingGroupRepository();
        $this->rows = new InMemoryPricingGroupPackageRepository();
        $this->defaults = new InMemoryDefaultPackagePriceRepository();
        $this->rates = new InMemoryClientExchangeRateRepository();
        $this->priceRules = new InMemoryPriceRuleRepository();
        $this->priceLists = new InMemoryPriceListRepository();
        $this->listPackages = new InMemoryPriceListPackageRepository();
        $this->packages = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro');
        $this->resolver = new PriceResolver(
            $this->groups,
            $this->rows,
            $this->defaults,
            $this->rates,
            $this->packages,
            new PriceListResolver($this->priceLists, $this->listPackages),
            new PriceRuleResolver($this->priceRules),
            new FrozenClock('2026-09-10T12:00:00+00:00'),
        );
        $this->defaults->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));
    }

    #[Test]
    public function aLowerPriorityGroupWinsAndAGlobalIosGroupShadowsDach(): void
    {
        $this->group('global-ios', priority: 1, countries: ['DE'], currency: 'EUR', deviceType: 'ios');
        $this->group('dach', priority: 2, countries: ['DE', 'AT', 'CH'], currency: 'EUR');
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);

        $ios = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE', 'ios');
        $web = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE', 'web');

        self::assertSame('global-ios', $this->priceOf($ios)->pricingGroupSlug);
        self::assertSame('dach', $this->priceOf($web)->pricingGroupSlug);
    }

    #[Test]
    public function anUnmatchedCountryFallsThroughToTheDefaultGroup(): void
    {
        $this->group('dach', priority: 1, countries: ['DE'], currency: 'EUR');
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);

        $result = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'FR');

        $price = $this->priceOf($result);
        self::assertTrue($price->pricingGroupIsDefault);
        self::assertSame(2900, $price->amountMinor);
        self::assertSame(PriceSource::Baseline, $price->source);
    }

    #[Test]
    public function noGroupAndNoDefaultIsANotFound(): void
    {
        $result = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'FR');

        self::assertTrue($result->isErr());
        self::assertSame('pricing.no_pricing_group', $result->error()->code);
    }

    #[Test]
    public function aCrossCurrencyDefaultConvertsViaTheClientRate(): void
    {
        $this->group('us', priority: 1, countries: ['US'], currency: 'USD');
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);
        $this->rates->save(new ClientExchangeRate(self::CLIENT, 'EUR', 'USD', '1.10', new DateTimeImmutable('2026-01-01')));

        $result = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'US');

        $price = $this->priceOf($result);
        self::assertSame('USD', $price->currencyCode);
        self::assertSame(PriceSource::Converted, $price->source);
        self::assertSame(3190, $price->amountMinor); // 29.00 * 1.10
    }

    #[Test]
    public function aCrossCurrencyDefaultWithoutARateIsAnError(): void
    {
        $this->group('us', priority: 1, countries: ['US'], currency: 'USD');
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);

        $result = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'US');

        self::assertTrue($result->isErr());
        self::assertSame('pricing.no_exchange_rate', $result->error()->code);
    }

    #[Test]
    public function anOverrideRowBypassesConversionAndTheBaseline(): void
    {
        $groupId = $this->group('dach', priority: 1, countries: ['DE'], currency: 'EUR');
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);
        $this->rows->save(PricingGroupPackage::create($groupId, self::PACKAGE, PricingRowStatus::Override, 2400, 'EUR', 'Pro (DACH)', null, true, 3, $this->now));

        $price = $this->priceOf($this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE'));

        self::assertSame(PriceSource::GroupOverride, $price->source);
        self::assertSame(2400, $price->amountMinor);
        self::assertSame('Pro (DACH)', $price->name);
        self::assertTrue($price->highlighted);
        self::assertSame(3, $price->displayOrder);
    }

    #[Test]
    public function aDisabledRowMakesThePackageUnavailableInThatGroup(): void
    {
        $groupId = $this->group('dach', priority: 1, countries: ['DE'], currency: 'EUR');
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);
        $this->rows->save(PricingGroupPackage::create($groupId, self::PACKAGE, PricingRowStatus::Disabled, null, null, null, null, null, 0, $this->now));

        $result = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE');

        self::assertTrue($result->isErr());
        self::assertSame('pricing.package_disabled_in_group', $result->error()->code);
    }

    #[Test]
    public function aDisabledGroupIsSkipped(): void
    {
        $groupId = $this->group('dach', priority: 1, countries: ['DE'], currency: 'EUR');
        $this->group('eu', priority: 2, countries: ['DE'], currency: 'EUR');
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);
        $this->groups->findById($groupId)?->disable($this->now);

        $price = $this->priceOf($this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE'));

        self::assertSame('eu', $price->pricingGroupSlug);
    }

    #[Test]
    public function anAvailablePriceRuleOverridesTheBaseAmount(): void
    {
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);
        $this->priceRules->save(PriceRule::create(
            self::CLIENT,
            self::PACKAGE,
            null,
            null,
            null,
            PaymentMethod::Card,
            null,
            null,
            'EUR',
            true,
            2500,
            $this->now,
        ));

        $withCard = $this->priceOf($this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE', null, PaymentMethod::Card));
        $noMethod = $this->priceOf($this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE'));

        self::assertSame(2500, $withCard->amountMinor);
        self::assertSame(PriceSource::DimensionOverride, $withCard->source);
        self::assertSame(['payment_method', 'currency_code'], $withCard->appliedDimensions);
        self::assertSame(2900, $noMethod->amountMinor); // rule pins card → not matched
    }

    #[Test]
    public function aMostSpecificUnavailableRuleFailsWithoutFallback(): void
    {
        $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);
        // general "subscription: €20" ...
        $this->priceRules->save(PriceRule::create(
            self::CLIENT,
            self::PACKAGE,
            null,
            null,
            null,
            null,
            PurchaseType::Subscription,
            null,
            'EUR',
            true,
            2000,
            $this->now,
        ));
        // ... but "subscription + yearly: unavailable" is more specific
        $this->priceRules->save(PriceRule::create(
            self::CLIENT,
            self::PACKAGE,
            null,
            null,
            null,
            null,
            PurchaseType::Subscription,
            SubscriptionInterval::Yearly,
            null,
            false,
            null,
            $this->now,
        ));

        $monthly = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE', null, null, PurchaseType::Subscription, SubscriptionInterval::Monthly);
        $yearly = $this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE', null, null, PurchaseType::Subscription, SubscriptionInterval::Yearly);

        self::assertSame(2000, $this->priceOf($monthly)->amountMinor);
        self::assertTrue($yearly->isErr());
        self::assertSame('pricing.combination_unavailable', $yearly->error()->code);
    }

    #[Test]
    public function anAssignedExperimentListShiftsTheBaseBeforePriceRules(): void
    {
        $groupId = $this->group('default', priority: 0, countries: [], currency: 'EUR', isDefault: true);

        $control = PriceList::control(self::CLIENT, $groupId, $this->now);
        $this->priceLists->save($control);
        $listB = PriceList::experiment(self::CLIENT, $groupId, 'List B', '0.9000', $this->now);
        $this->priceLists->save($listB);
        $listBId = $listB->id();
        \assert($listBId !== null);

        // a card price rule still wins over the experiment for card buyers
        $this->priceRules->save(PriceRule::create(
            self::CLIENT,
            self::PACKAGE,
            null,
            null,
            null,
            PaymentMethod::Card,
            null,
            null,
            'EUR',
            true,
            2500,
            $this->now,
        ));

        $onList = $this->priceOf($this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE', null, null, null, null, null, $listBId));
        self::assertSame(2610, $onList->amountMinor); // 2900 * 0.9
        self::assertSame(PriceSource::PriceList, $onList->source);
        self::assertSame($listBId, $onList->priceListId);

        $onControl = $this->priceOf($this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE'));
        self::assertSame(2900, $onControl->amountMinor);

        $cardOnList = $this->priceOf($this->resolver->resolve(self::CLIENT, self::PACKAGE, 'DE', null, PaymentMethod::Card, null, null, null, $listBId));
        self::assertSame(2500, $cardOnList->amountMinor); // price rule overrides the experiment
        self::assertSame(PriceSource::DimensionOverride, $cardOnList->source);
        self::assertSame($listBId, $cardOnList->priceListId); // still stamped
    }

    /**
     * @param list<string> $countries
     */
    private function group(string $slug, int $priority, array $countries, string $currency, ?string $deviceType = null, bool $isDefault = false): int
    {
        $group = PricingGroup::define(self::CLIENT, PricingGroupSlug::of($slug), ucfirst($slug), $priority, $deviceType, $currency, $isDefault, $this->now);
        $group->setCountries($countries, $this->now);
        $this->groups->save($group);
        $id = $group->id();
        \assert($id !== null);

        return $id;
    }

    private function priceOf(\Gomrok\Shared\Domain\Result $result): ResolvedPrice
    {
        self::assertTrue($result->isOk(), $result->isErr() ? $result->error()->code : '');
        $price = $result->value();
        self::assertInstanceOf(ResolvedPrice::class, $price);

        return $price;
    }
}
