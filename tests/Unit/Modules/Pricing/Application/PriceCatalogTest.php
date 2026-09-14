<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Application;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Application\PackageCatalog;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Pricing\Application\PriceCatalog;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Application\ResolvedCatalogPackage;
use Gomrok\Modules\Pricing\Application\ResolveVisitorPriceListAssignment;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListAssignmentRepository;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceCatalogTest extends TestCase
{
    private const CLIENT = 7;

    #[Test]
    public function pricesEveryPackageAndSortsByDisplayOrderDroppingDisabled(): void
    {
        $now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');

        $packageRepo = new InMemoryPackageRepository();
        $starter = $this->package($packageRepo, 'starter', $now);
        $pro = $this->package($packageRepo, 'pro', $now);
        $legacy = $this->package($packageRepo, 'legacy', $now);

        $directory = (new StubPackageDirectory())
            ->add($starter, self::CLIENT, 'starter')
            ->add($pro, self::CLIENT, 'pro')
            ->add($legacy, self::CLIENT, 'legacy');

        $groups = new InMemoryPricingGroupRepository();
        $group = PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now);
        $groups->save($group);
        $groupId = $group->id();
        \assert($groupId !== null);

        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice($starter, 900, 'EUR'));
        $defaults->save(new DefaultPackagePrice($pro, 2900, 'EUR'));
        $defaults->save(new DefaultPackagePrice($legacy, 100, 'EUR'));

        $rows = new InMemoryPricingGroupPackageRepository();
        $rows->save(PricingGroupPackage::create($groupId, $pro, PricingRowStatus::Default, null, null, null, null, null, 1, $now));
        $rows->save(PricingGroupPackage::create($groupId, $starter, PricingRowStatus::Default, null, null, null, null, null, 2, $now));
        $rows->save(PricingGroupPackage::create($groupId, $legacy, PricingRowStatus::Disabled, null, null, null, null, null, 0, $now));

        $clock = new FrozenClock('2026-09-10T12:00:00+00:00');
        $priceListRepository = new InMemoryPriceListRepository();
        $priceListResolver = new PriceListResolver($priceListRepository, new InMemoryPriceListPackageRepository());
        $resolver = new PriceResolver(
            $groups,
            $rows,
            $defaults,
            new InMemoryClientExchangeRateRepository(),
            $directory,
            $priceListResolver,
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            new ResolveVisitorPriceListAssignment(new InMemoryPriceListAssignmentRepository(), $priceListRepository, $clock),
            $clock,
        );

        $packageCatalog = new PackageCatalog($packageRepo, new StubProviderAccountDirectory(), new PackagePurchaseCapabilityResolver($packageRepo));
        $assignments = new InMemoryPriceListAssignmentRepository();
        $catalog = new PriceCatalog($packageCatalog, $resolver, $priceListResolver, new ResolveVisitorPriceListAssignment($assignments, $priceListRepository, $clock));

        $result = $catalog->resolve(self::CLIENT, 'DE');
        self::assertTrue($result->isOk());

        /** @var list<ResolvedCatalogPackage> $items */
        $items = $result->value();
        $codes = array_map(static fn (ResolvedCatalogPackage $i): string => $i->package->code, $items);

        self::assertSame(['pro', 'starter'], $codes); // legacy disabled; pro (order 1) before starter (order 2)
        self::assertSame(2900, $items[0]->price->amountMinor);

        $asArray = PriceCatalog::toArray($items);
        self::assertSame('pro', $asArray[0]['code']);
        self::assertArrayHasKey('price', $asArray[0]);
    }

    #[Test]
    public function aVisitorRefBucketsIntoAnAbListAndPersistsTheAssignment(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $packageRepo = new InMemoryPackageRepository();
        $pro = $this->package($packageRepo, 'pro', $now);
        $directory = (new StubPackageDirectory())->add($pro, self::CLIENT, 'pro');

        $groups = new InMemoryPricingGroupRepository();
        $group = PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now);
        $groups->save($group);
        $groupId = $group->id();
        \assert($groupId !== null);

        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice($pro, 2900, 'EUR'));

        $priceListRepository = new InMemoryPriceListRepository();
        $priceListRepository->save(PriceList::control(self::CLIENT, $groupId, $now));
        $priceListRepository->save(PriceList::experiment(self::CLIENT, $groupId, 'List B', '0.9000', $now));
        $priceListResolver = new PriceListResolver($priceListRepository, new InMemoryPriceListPackageRepository());

        $assignments = new InMemoryPriceListAssignmentRepository();
        $visitorAssignment = new ResolveVisitorPriceListAssignment($assignments, $priceListRepository, new FrozenClock('2026-09-14T12:00:00+00:00'));

        $resolver = new PriceResolver(
            $groups,
            new InMemoryPricingGroupPackageRepository(),
            $defaults,
            new InMemoryClientExchangeRateRepository(),
            $directory,
            $priceListResolver,
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            $visitorAssignment,
            new FrozenClock('2026-09-14T12:00:00+00:00'),
        );
        $packageCatalog = new PackageCatalog($packageRepo, new StubProviderAccountDirectory(), new PackagePurchaseCapabilityResolver($packageRepo));
        $catalog = new PriceCatalog($packageCatalog, $resolver, $priceListResolver, $visitorAssignment);

        $first = $catalog->resolve(self::CLIENT, 'DE', null, null, 'visitor-abc');
        self::assertTrue($first->isOk());
        /** @var list<ResolvedCatalogPackage> $firstItems */
        $firstItems = $first->value();
        $firstAmount = $firstItems[0]->price->amountMinor;

        // A stored assignment exists and a second call for the same visitor
        // returns the identical (stable) amount, whichever bucket it landed in.
        $hash = hash('sha256', $groupId . ':visitor-abc');
        self::assertNotNull($assignments->findByGroupAndHash($groupId, $hash));

        $second = $catalog->resolve(self::CLIENT, 'DE', null, null, 'visitor-abc');
        self::assertTrue($second->isOk());
        /** @var list<ResolvedCatalogPackage> $secondItems */
        $secondItems = $second->value();
        self::assertSame($firstAmount, $secondItems[0]->price->amountMinor);
        self::assertContains($firstAmount, [2900, 2610]); // control (2900) or List B at 0.9x (2610)
    }

    private function package(InMemoryPackageRepository $repo, string $code, DateTimeImmutable $now): int
    {
        $package = Package::create(self::CLIENT, PackageCode::of($code), ucfirst($code), null, null, $now);
        $package->setPurchaseCapabilities([PackagePurchaseCapability::of(PurchaseType::OneTimePayment)], $now);
        $repo->save($package);
        $id = $package->id();
        \assert($id !== null);

        return $id;
    }
}
