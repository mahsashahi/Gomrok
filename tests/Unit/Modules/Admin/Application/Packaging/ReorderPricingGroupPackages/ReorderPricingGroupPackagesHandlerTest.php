<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages\ReorderPricingGroupPackagesCommand;
use Gomrok\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages\ReorderPricingGroupPackagesHandler;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryPackageDirectory;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReorderPricingGroupPackagesHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPricingGroupPackageRepository $rows;
    private ReorderPricingGroupPackagesHandler $handler;
    private int $groupId;
    private int $packageA;
    private int $packageB;
    private int $packageC;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-16T12:00:00+00:00');

        $groups = new InMemoryPricingGroupRepository();
        $group = PricingGroup::define(self::CLIENT, PricingGroupSlug::of('dach'), 'DACH', 1, null, 'EUR', false, $now);
        $groups->save($group);
        $groupId = $group->id();
        \assert($groupId !== null);
        $this->groupId = $groupId;

        $packages = new InMemoryPackageRepository();
        $a = Package::create(self::CLIENT, PackageCode::of('a'), 'A', null, null, $now);
        $b = Package::create(self::CLIENT, PackageCode::of('b'), 'B', null, null, $now);
        $c = Package::create(self::CLIENT, PackageCode::of('c'), 'C', null, null, $now);
        $packages->save($a);
        $packages->save($b);
        $packages->save($c);
        $idA = $a->id();
        $idB = $b->id();
        $idC = $c->id();
        \assert($idA !== null && $idB !== null && $idC !== null);
        $this->packageA = $idA;
        $this->packageB = $idB;
        $this->packageC = $idC;

        $this->rows = new InMemoryPricingGroupPackageRepository();

        // Package B already carries a price override — reordering must not
        // clobber it (the very bug this handler exists to avoid).
        $this->rows->save(PricingGroupPackage::create($this->groupId, $this->packageB, PricingRowStatus::Override, 1990, 'EUR', null, 'Best seller', true, 1, $now));

        $setPackage = new SetPricingGroupPackageHandler(
            $this->rows,
            $groups,
            new InMemoryPackageDirectory($packages),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            $clock,
        );

        $this->handler = new ReorderPricingGroupPackagesHandler($this->rows, $setPackage);
    }

    #[Test]
    public function reordersPackagesByTheGivenSequence(): void
    {
        $result = $this->handler->handle(new ReorderPricingGroupPackagesCommand(
            pricingGroupId: $this->groupId,
            packageIdsInOrder: [$this->packageC, $this->packageA, $this->packageB],
        ));

        self::assertTrue($result->isOk());
        self::assertSame(0, $this->rows->find($this->groupId, $this->packageC)?->displayOrder());
        self::assertSame(1, $this->rows->find($this->groupId, $this->packageA)?->displayOrder());
        self::assertSame(2, $this->rows->find($this->groupId, $this->packageB)?->displayOrder());
    }

    #[Test]
    public function preservesAnExistingPackagesPriceOverrideAndCosmeticFields(): void
    {
        $result = $this->handler->handle(new ReorderPricingGroupPackagesCommand(
            pricingGroupId: $this->groupId,
            packageIdsInOrder: [$this->packageB, $this->packageA, $this->packageC],
        ));

        self::assertTrue($result->isOk());
        $row = $this->rows->find($this->groupId, $this->packageB);
        self::assertNotNull($row);
        self::assertSame(0, $row->displayOrder());
        self::assertSame(PricingRowStatus::Override, $row->status());
        self::assertSame(1990, $row->amountMinor());
        self::assertSame('EUR', $row->currencyCode());
        self::assertSame('Best seller', $row->badgeOverride());
        self::assertTrue($row->highlightedOverride());
    }

    #[Test]
    public function createsAnImplicitDefaultRowForAPackageWithNoPriorRow(): void
    {
        $result = $this->handler->handle(new ReorderPricingGroupPackagesCommand(
            pricingGroupId: $this->groupId,
            packageIdsInOrder: [$this->packageA, $this->packageB, $this->packageC],
        ));

        self::assertTrue($result->isOk());
        $row = $this->rows->find($this->groupId, $this->packageA);
        self::assertNotNull($row);
        self::assertSame(PricingRowStatus::Default, $row->status());
        self::assertNull($row->amountMinor());
        self::assertSame(0, $row->displayOrder());
    }
}
