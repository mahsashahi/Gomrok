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
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\ResolvedCatalogPackage;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
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

        $resolver = new PriceResolver(
            $groups,
            $rows,
            $defaults,
            new InMemoryClientExchangeRateRepository(),
            $directory,
            new \Gomrok\Modules\Pricing\Application\PriceListResolver(
                new \Gomrok\Tests\Support\InMemoryPriceListRepository(),
                new \Gomrok\Tests\Support\InMemoryPriceListPackageRepository(),
            ),
            new \Gomrok\Modules\Pricing\Application\PriceRuleResolver(new \Gomrok\Tests\Support\InMemoryPriceRuleRepository()),
            new FrozenClock('2026-09-10T12:00:00+00:00'),
        );

        $packageCatalog = new PackageCatalog($packageRepo, new StubProviderAccountDirectory(), new PackagePurchaseCapabilityResolver($packageRepo));
        $catalog = new PriceCatalog($packageCatalog, $resolver);

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
