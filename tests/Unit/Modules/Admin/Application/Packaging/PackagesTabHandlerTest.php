<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Packaging;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Packaging\PackagesTabHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Application\ResolveVisitorPriceListAssignment;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPackageProviderDefinitionDirectory;
use Gomrok\Tests\Support\InMemoryPackageProviderDefinitionRepository;
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

final class PackagesTabHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private DateTimeImmutable $now;
    private InMemoryPricingGroupRepository $groups;
    private InMemoryClientDirectory $clients;
    private StubPackageDirectory $packages;
    private PackagesTabHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $this->clients = new InMemoryClientDirectory();
        $this->clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $this->packages = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro package');

        $this->groups = new InMemoryPricingGroupRepository();
        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));
        $priceLists = new InMemoryPriceListRepository();
        $listPackages = new InMemoryPriceListPackageRepository();
        $rows = new InMemoryPricingGroupPackageRepository();
        $rates = new InMemoryClientExchangeRateRepository();
        $priceListResolver = new PriceListResolver($priceLists, $listPackages);

        $priceResolver = new PriceResolver(
            $this->groups,
            $rows,
            $defaults,
            $rates,
            $this->packages,
            $priceListResolver,
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            new ResolveVisitorPriceListAssignment(new InMemoryPriceListAssignmentRepository(), $priceLists, new FrozenClock('2026-09-16T12:00:00+00:00')),
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );

        $this->handler = new PackagesTabHandler(
            $this->clients,
            $this->packages,
            new PackagePurchaseCapabilityResolver(new InMemoryPackageRepository()),
            new InMemoryPackageProviderDefinitionDirectory(new InMemoryPackageProviderDefinitionRepository()),
            new StubProviderAccountDirectory(),
            $this->groups,
            $priceResolver,
            $priceListResolver,
            $rates,
            $defaults,
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );
    }

    #[Test]
    public function listsEveryPackageForTheClient(): void
    {
        $result = $this->handler->forClient(self::CLIENT, null);

        self::assertCount(1, $result->packages);
        self::assertSame('pro', $result->packages[0]->code);
        self::assertSame('€29.00', $result->packages[0]->defaultPrice);
    }

    #[Test]
    public function selectsTheRequestedPackageOrFallsBackToTheFirst(): void
    {
        $withSelection = $this->handler->forClient(self::CLIENT, 'pro');
        self::assertNotNull($withSelection->selected);
        self::assertSame('pro', $withSelection->selected->code);

        $withoutSelection = $this->handler->forClient(self::CLIENT, null);
        self::assertNotNull($withoutSelection->selected);
        self::assertSame('pro', $withoutSelection->selected->code);
    }

    #[Test]
    public function theDetailShowsAResolvedPriceForEachActiveGroup(): void
    {
        $this->groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('dach'), 'DACH', 1, null, 'EUR', false, $this->now));
        $this->groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $this->now));

        $result = $this->handler->forClient(self::CLIENT, 'pro');

        self::assertNotNull($result->selected);
        self::assertCount(2, $result->selected->byGroupRows);
        foreach ($result->selected->byGroupRows as $row) {
            self::assertSame('€29.00', $row->price);
        }
    }

    #[Test]
    public function anUnknownClientHasNoPackages(): void
    {
        $result = $this->handler->forClient(999, null);

        self::assertSame([], $result->packages);
        self::assertNull($result->selected);
    }
}
