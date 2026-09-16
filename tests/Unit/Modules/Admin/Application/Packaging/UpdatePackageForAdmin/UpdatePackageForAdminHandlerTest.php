<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Packaging\UpdatePackageForAdmin;

use Gomrok\Modules\Admin\Application\Packaging\UpdatePackageForAdmin\UpdatePackageForAdminCommand;
use Gomrok\Modules\Admin\Application\Packaging\UpdatePackageForAdmin\UpdatePackageForAdminHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Packages\Application\ChangePackageStatus\ChangePackageStatusHandler;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesHandler;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageHandler;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceHandler;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPackageDirectory;
use Gomrok\Tests\Support\InMemoryPackageProviderDefinitionRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UpdatePackageForAdminHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPackageRepository $packages;
    private InMemoryDefaultPackagePriceRepository $defaultPrices;
    private UpdatePackageForAdminHandler $handler;
    private int $packageId;

    protected function setUp(): void
    {
        $clock = new FrozenClock('2026-09-16T12:00:00+00:00');
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $this->packages = new InMemoryPackageRepository();
        $definitions = new InMemoryPackageProviderDefinitionRepository();
        $audit = new RecordingAuditLogWriter();
        $transactions = new SynchronousTransactions();
        $this->defaultPrices = new InMemoryDefaultPackagePriceRepository();
        $packageDirectory = new InMemoryPackageDirectory($this->packages);

        $package = Package::create(self::CLIENT, PackageCode::of('pro-monthly'), 'Pro Monthly', null, null, $clock->now());
        $this->packages->save($package);
        $id = $package->id();
        \assert($id !== null);
        $this->packageId = $id;

        $this->handler = new UpdatePackageForAdminHandler(
            new UpdatePackageHandler($this->packages, $definitions, $audit, $transactions, $clock),
            new SetPackagePurchaseCapabilitiesHandler($this->packages, $definitions, $audit, $transactions, $clock),
            new SetDefaultPackagePriceHandler($this->defaultPrices, $packageDirectory, new InMemoryReferenceCatalog(), $audit, $transactions),
            new ChangePackageStatusHandler($this->packages, $audit, $transactions, $clock),
        );
    }

    #[Test]
    public function updatesNameCapabilitiesAndPrice(): void
    {
        $result = $this->handler->handle(new UpdatePackageForAdminCommand(
            packageId: $this->packageId,
            name: 'Pro Monthly Renamed',
            description: 'Updated description',
            badge: 'Best value',
            highlighted: true,
            supportsOneTime: false,
            supportsSubscription: true,
            durationMonths: 1,
            hasTrial: true,
            trialDays: 7,
            defaultPriceAmountMinor: 3500,
            defaultPriceCurrency: 'EUR',
            active: true,
        ));

        self::assertTrue($result->isOk());

        $package = $this->packages->findById($this->packageId);
        self::assertNotNull($package);
        self::assertSame('Pro Monthly Renamed', $package->name());
        self::assertSame('Best value', $package->badge());
        self::assertTrue($package->highlighted());
        self::assertTrue($package->supportsPurchaseTypeGlobally(PurchaseType::Subscription));
        self::assertFalse($package->supportsPurchaseTypeGlobally(PurchaseType::OneTimePayment));
        self::assertTrue($package->isActive());

        $price = $this->defaultPrices->find($this->packageId);
        self::assertNotNull($price);
        self::assertSame(3500, $price->amountMinor);
    }

    #[Test]
    public function disablesThePackage(): void
    {
        $result = $this->handler->handle(new UpdatePackageForAdminCommand(
            packageId: $this->packageId,
            name: 'Pro Monthly',
            description: null,
            badge: null,
            highlighted: false,
            supportsOneTime: true,
            supportsSubscription: false,
            durationMonths: null,
            hasTrial: false,
            trialDays: null,
            defaultPriceAmountMinor: null,
            defaultPriceCurrency: null,
            active: false,
        ));

        self::assertTrue($result->isOk());
        $package = $this->packages->findById($this->packageId);
        self::assertNotNull($package);
        self::assertFalse($package->isActive());
    }

    #[Test]
    public function rejectsWhenNeitherPurchaseTypeIsSelected(): void
    {
        $result = $this->handler->handle(new UpdatePackageForAdminCommand(
            packageId: $this->packageId,
            name: 'Pro Monthly',
            description: null,
            badge: null,
            highlighted: false,
            supportsOneTime: false,
            supportsSubscription: false,
            durationMonths: null,
            hasTrial: false,
            trialDays: null,
            defaultPriceAmountMinor: null,
            defaultPriceCurrency: null,
            active: true,
        ));

        self::assertTrue($result->isErr());
        self::assertSame('package.no_purchase_type', $result->error()->code);
    }
}
