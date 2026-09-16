<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Packaging\SetGroupPackagePriceForAdmin;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Packaging\SetGroupPackagePriceForAdmin\SetGroupPackagePriceForAdminCommand;
use Gomrok\Modules\Admin\Application\Packaging\SetGroupPackagePriceForAdmin\SetGroupPackagePriceForAdminHandler;
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

final class SetGroupPackagePriceForAdminHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPricingGroupPackageRepository $rows;
    private SetGroupPackagePriceForAdminHandler $handler;
    private int $groupId;
    private int $packageId;

    protected function setUp(): void
    {
        $clock = new FrozenClock('2026-09-16T12:00:00+00:00');
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');

        $groups = new InMemoryPricingGroupRepository();
        $group = PricingGroup::define(self::CLIENT, PricingGroupSlug::of('dach'), 'DACH', 1, null, 'EUR', false, $now);
        $groups->save($group);
        $groupId = $group->id();
        \assert($groupId !== null);
        $this->groupId = $groupId;

        $packages = new InMemoryPackageRepository();
        $package = Package::create(self::CLIENT, PackageCode::of('pro'), 'Pro', null, null, $now);
        $packages->save($package);
        $packageId = $package->id();
        \assert($packageId !== null);
        $this->packageId = $packageId;

        $this->rows = new InMemoryPricingGroupPackageRepository();
        $setPackage = new SetPricingGroupPackageHandler(
            $this->rows,
            $groups,
            new InMemoryPackageDirectory($packages),
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            $clock,
        );

        $this->handler = new SetGroupPackagePriceForAdminHandler($this->rows, $setPackage);
    }

    #[Test]
    public function setsAnOverridePriceForANewRow(): void
    {
        $result = $this->handler->handle(new SetGroupPackagePriceForAdminCommand(
            pricingGroupId: $this->groupId,
            packageId: $this->packageId,
            status: 'override',
            amountMinor: 1550,
            currency: 'EUR',
        ));

        self::assertTrue($result->isOk());
        $row = $this->rows->find($this->groupId, $this->packageId);
        self::assertNotNull($row);
        self::assertSame(PricingRowStatus::Override, $row->status());
        self::assertSame(1550, $row->amountMinor());
        self::assertSame(0, $row->displayOrder());
    }

    #[Test]
    public function preservesTheExistingDisplayOrderWhenEditingAnExistingRow(): void
    {
        // A prior drag-to-reorder already gave this package row display order 3.
        $reordered = PricingGroupPackage::create(
            $this->groupId,
            $this->packageId,
            PricingRowStatus::Override,
            1000,
            'EUR',
            null,
            null,
            null,
            3,
            new DateTimeImmutable('2026-09-16T12:00:00+00:00'),
        );
        $this->rows->save($reordered);

        $result = $this->handler->handle(new SetGroupPackagePriceForAdminCommand(
            pricingGroupId: $this->groupId,
            packageId: $this->packageId,
            status: 'override',
            amountMinor: 2000,
            currency: 'EUR',
        ));

        self::assertTrue($result->isOk());
        $row = $this->rows->find($this->groupId, $this->packageId);
        self::assertNotNull($row);
        self::assertSame(2000, $row->amountMinor());
        self::assertSame(3, $row->displayOrder());
    }

    #[Test]
    public function settingStatusToDisabledClearsTheAmount(): void
    {
        $result = $this->handler->handle(new SetGroupPackagePriceForAdminCommand(
            pricingGroupId: $this->groupId,
            packageId: $this->packageId,
            status: 'disabled',
            amountMinor: null,
            currency: null,
        ));

        self::assertTrue($result->isOk());
        $row = $this->rows->find($this->groupId, $this->packageId);
        self::assertNotNull($row);
        self::assertSame(PricingRowStatus::Disabled, $row->status());
        self::assertNull($row->amountMinor());
    }
}
