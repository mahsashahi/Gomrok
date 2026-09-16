<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Packaging;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Packaging\GroupsTabHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Application\ResolveVisitorPriceListAssignment;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPriceListAssignmentRepository;
use Gomrok\Tests\Support\InMemoryPriceListDirectory;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GroupsTabHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private DateTimeImmutable $now;
    private InMemoryPricingGroupRepository $groups;
    private InMemoryPriceListRepository $priceLists;
    private GroupsTabHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $packages = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro package');

        $this->groups = new InMemoryPricingGroupRepository();
        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));
        $this->priceLists = new InMemoryPriceListRepository();
        $listPackages = new InMemoryPriceListPackageRepository();
        $rows = new InMemoryPricingGroupPackageRepository();
        $rates = new InMemoryClientExchangeRateRepository();
        $priceListResolver = new PriceListResolver($this->priceLists, $listPackages);

        $priceResolver = new PriceResolver(
            $this->groups,
            $rows,
            $defaults,
            $rates,
            $packages,
            $priceListResolver,
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            new ResolveVisitorPriceListAssignment(new InMemoryPriceListAssignmentRepository(), $this->priceLists, new FrozenClock('2026-09-16T12:00:00+00:00')),
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );

        $this->handler = new GroupsTabHandler(
            $clients,
            $packages,
            $this->groups,
            new InMemoryPriceListDirectory($this->priceLists, $listPackages),
            $priceResolver,
            $priceListResolver,
            new StubProviderAccountDirectory(),
            $rates,
            $defaults,
            $rows,
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );

        $this->groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('dach'), 'DACH', 1, null, 'EUR', false, $this->now));
    }

    #[Test]
    public function listsEveryGroupForTheClient(): void
    {
        $result = $this->handler->forClient(self::CLIENT, null, null);

        self::assertCount(1, $result->groups);
        self::assertSame('dach', $result->groups[0]->slug);
    }

    #[Test]
    public function theDetailResolvesEveryPackagesPriceInTheGroup(): void
    {
        $result = $this->handler->forClient(self::CLIENT, 'dach', null);

        self::assertNotNull($result->selected);
        self::assertCount(1, $result->selected->packageRows);
        self::assertSame('€29.00', $result->selected->packageRows[0]->groupPrice);
        self::assertSame('Control', $result->selected->activePriceListName);
    }

    #[Test]
    public function selectingANonControlEnabledListChangesTheResolvedPrice(): void
    {
        $groupId = $this->groups->forClient(self::CLIENT)[0]->id();
        \assert($groupId !== null);

        $variant = PriceList::experiment(self::CLIENT, $groupId, 'List B', '0.9000', $this->now);
        $variant->enable($this->now);
        $this->priceLists->save($variant);
        $variantId = $variant->id();
        \assert($variantId !== null);

        $result = $this->handler->forClient(self::CLIENT, 'dach', $variantId);

        self::assertNotNull($result->selected);
        self::assertSame('List B', $result->selected->activePriceListName);
        self::assertSame('€26.10', $result->selected->packageRows[0]->groupPrice);
    }

    #[Test]
    public function selectingADisabledListHighlightsItButFallsBackToControlForPricing(): void
    {
        $groupId = $this->groups->forClient(self::CLIENT)[0]->id();
        \assert($groupId !== null);

        $variant = PriceList::experiment(self::CLIENT, $groupId, 'List B', '0.9000', $this->now);
        $variant->disable($this->now);
        $this->priceLists->save($variant);
        $variantId = $variant->id();
        \assert($variantId !== null);

        $result = $this->handler->forClient(self::CLIENT, 'dach', $variantId);

        self::assertNotNull($result->selected);
        // The math and header reflect the real fallback (control), not the disabled list.
        self::assertSame('Control', $result->selected->activePriceListName);
        self::assertSame('€29.00', $result->selected->packageRows[0]->groupPrice);

        // But the disabled list's own card still shows as the one the admin clicked.
        $variantItem = null;
        foreach ($result->selected->priceLists as $item) {
            if ($item->id === $variantId) {
                $variantItem = $item;
            }
        }
        self::assertNotNull($variantItem);
        self::assertTrue($variantItem->selected);
        self::assertFalse($variantItem->isEnabled);
    }

    #[Test]
    public function anUnknownClientHasNoGroups(): void
    {
        $result = $this->handler->forClient(999, null, null);

        self::assertSame([], $result->groups);
        self::assertNull($result->selected);
    }
}
