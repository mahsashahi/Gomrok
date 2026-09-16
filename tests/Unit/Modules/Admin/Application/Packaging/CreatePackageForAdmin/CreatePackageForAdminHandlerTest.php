<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Packaging\CreatePackageForAdmin;

use Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin\CreatePackageForAdminCommand;
use Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin\CreatePackageForAdminHandler;
use Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin\CreatePackageForAdminResult;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageHandler;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesHandler;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageHandler;
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

final class CreatePackageForAdminHandlerTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPackageRepository $packages;
    private InMemoryDefaultPackagePriceRepository $defaultPrices;
    private CreatePackageForAdminHandler $handler;

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

        $this->handler = new CreatePackageForAdminHandler(
            new CreatePackageHandler($this->packages, $clients, $audit, $transactions, $clock),
            new UpdatePackageHandler($this->packages, $definitions, $audit, $transactions, $clock),
            new SetPackagePurchaseCapabilitiesHandler($this->packages, $definitions, $audit, $transactions, $clock),
            new SetDefaultPackagePriceHandler($this->defaultPrices, $packageDirectory, new InMemoryReferenceCatalog(), $audit, $transactions),
        );
    }

    #[Test]
    public function createsAOneTimePackageWithADefaultPrice(): void
    {
        $result = $this->handler->handle(new CreatePackageForAdminCommand(
            clientId: self::CLIENT,
            code: 'pro-monthly',
            name: 'Pro Monthly',
            description: 'The pro plan',
            badge: 'Popular',
            highlighted: true,
            supportsOneTime: true,
            supportsSubscription: false,
            durationMonths: 1,
            hasTrial: false,
            trialDays: null,
            defaultPriceAmountMinor: 2900,
            defaultPriceCurrency: 'EUR',
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        self::assertInstanceOf(CreatePackageForAdminResult::class, $value);
        self::assertSame('pro-monthly', $value->code);

        $package = $this->packages->findById($value->packageId);
        self::assertNotNull($package);
        self::assertSame('Pro Monthly', $package->name());
        self::assertSame('Popular', $package->badge());
        self::assertTrue($package->highlighted());
        self::assertTrue($package->supportsPurchaseTypeGlobally(PurchaseType::OneTimePayment));
        self::assertFalse($package->supportsPurchaseTypeGlobally(PurchaseType::Subscription));

        $price = $this->defaultPrices->find($value->packageId);
        self::assertNotNull($price);
        self::assertSame(2900, $price->amountMinor);
        self::assertSame('EUR', $price->currencyCode);
    }

    #[Test]
    public function createsASubscriptionPackageWithATrial(): void
    {
        $result = $this->handler->handle(new CreatePackageForAdminCommand(
            clientId: self::CLIENT,
            code: 'pro-sub',
            name: 'Pro Subscription',
            description: null,
            badge: null,
            highlighted: false,
            supportsOneTime: false,
            supportsSubscription: true,
            durationMonths: null,
            hasTrial: true,
            trialDays: 14,
            defaultPriceAmountMinor: null,
            defaultPriceCurrency: null,
        ));

        self::assertTrue($result->isOk());
        $value = $result->value();
        self::assertInstanceOf(CreatePackageForAdminResult::class, $value);

        $package = $this->packages->findById($value->packageId);
        self::assertNotNull($package);
        self::assertTrue($package->supportsPurchaseTypeGlobally(PurchaseType::Subscription));
        self::assertFalse($package->supportsPurchaseTypeGlobally(PurchaseType::OneTimePayment));
        self::assertNull($this->defaultPrices->find($value->packageId));
    }

    #[Test]
    public function rejectsWhenNeitherPurchaseTypeIsSelected(): void
    {
        $result = $this->handler->handle(new CreatePackageForAdminCommand(
            clientId: self::CLIENT,
            code: 'no-type',
            name: 'No Type',
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
        ));

        self::assertTrue($result->isErr());
        self::assertSame('package.no_purchase_type', $result->error()->code);
        self::assertSame([], $this->packages->forClient(self::CLIENT));
    }
}
