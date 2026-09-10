<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Packages\Application;

use Gomrok\Modules\Packages\Application\ChangePackageProviderSyncState\ChangePackageProviderSyncStateHandler;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderCommand;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderHandler;
use Gomrok\Modules\Packages\Application\SetPackageCountryPurchaseCapabilities\SetPackageCountryPurchaseCapabilitiesCommand;
use Gomrok\Modules\Packages\Application\SetPackageCountryPurchaseCapabilities\SetPackageCountryPurchaseCapabilitiesHandler;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\PurchaseCapabilityInput;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesCommand;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesHandler;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageCommand;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageHandler;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackageProviderSyncState;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryPackageProviderDefinitionRepository;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageCapabilityHandlersTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPackageRepository $packages;
    private InMemoryPackageProviderDefinitionRepository $definitions;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;
    private int $packageId;

    protected function setUp(): void
    {
        $this->packages = new InMemoryPackageRepository();
        $this->definitions = new InMemoryPackageProviderDefinitionRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-10T12:00:00+00:00');

        $package = Package::create(self::CLIENT, PackageCode::of('pro'), 'Pro', null, null, $this->clock->now());
        $this->packages->save($package);
        $id = $package->id();
        self::assertNotNull($id);
        $this->packageId = $id;
    }

    #[Test]
    public function setPurchaseCapabilitiesValidatesTrialRulesAndDuplicates(): void
    {
        $handler = $this->capabilitiesHandler();

        self::assertSame(
            'package.trial_not_allowed',
            $handler->handle(new SetPackagePurchaseCapabilitiesCommand($this->packageId, [
                new PurchaseCapabilityInput('one_time_payment', hasTrial: true, trialDays: 7),
            ]))->error()->code,
        );
        self::assertSame(
            'package.duplicate_purchase_type',
            $handler->handle(new SetPackagePurchaseCapabilitiesCommand($this->packageId, [
                new PurchaseCapabilityInput('subscription'),
                new PurchaseCapabilityInput('subscription'),
            ]))->error()->code,
        );

        $ok = $handler->handle(new SetPackagePurchaseCapabilitiesCommand($this->packageId, [
            new PurchaseCapabilityInput('one_time_payment', durationMonths: 1),
            new PurchaseCapabilityInput('subscription', hasTrial: true, trialDays: 14, durationMonths: 1),
        ]));
        self::assertTrue($ok->isOk());

        $stored = $this->packages->findById($this->packageId);
        self::assertNotNull($stored);
        self::assertCount(2, $stored->purchaseCapabilities());
        self::assertContains('package.capabilities_updated', $this->audit->actions());
    }

    #[Test]
    public function countryOverrideMustBeASubsetOfTheGlobalSet(): void
    {
        $this->capabilitiesHandler()->handle(new SetPackagePurchaseCapabilitiesCommand($this->packageId, [
            new PurchaseCapabilityInput('one_time_payment'),
        ]));

        $handler = new SetPackageCountryPurchaseCapabilitiesHandler(
            $this->packages,
            new InMemoryReferenceCatalog(),
            $this->definitions,
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );

        self::assertSame(
            'package.override_not_in_global_set',
            $handler->handle(new SetPackageCountryPurchaseCapabilitiesCommand($this->packageId, ['TR' => ['subscription']]))->error()->code,
        );
        self::assertSame(
            'package.unknown_country',
            $handler->handle(new SetPackageCountryPurchaseCapabilitiesCommand($this->packageId, ['ZZ' => ['one_time_payment']]))->error()->code,
        );

        self::assertTrue(
            $handler->handle(new SetPackageCountryPurchaseCapabilitiesCommand($this->packageId, ['TR' => ['one_time_payment']]))->isOk(),
        );
    }

    #[Test]
    public function linkPackageProviderCreatesThenSyncsARow(): void
    {
        $accounts = (new StubProviderAccountDirectory())->add(9, self::CLIENT, 'stripe', 'stripe');
        $handler = new LinkPackageProviderHandler(
            $this->packages,
            $this->definitions,
            $accounts,
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );

        self::assertSame(
            'package.account_not_owned',
            $handler->handle(new LinkPackageProviderCommand($this->packageId, 404))->error()->code,
        );

        self::assertTrue($handler->handle(new LinkPackageProviderCommand($this->packageId, 9, 'Pro (Stripe)'))->isOk());
        $definition = $this->definitions->findByPackageAndAccount($this->packageId, 9);
        self::assertNotNull($definition);
        self::assertSame(PackageProviderSyncState::NotCreated, $definition->syncState());

        self::assertTrue($handler->handle(new LinkPackageProviderCommand($this->packageId, 9, 'Pro (Stripe)', 'prod_ABC'))->isOk());
        $definition = $this->definitions->findByPackageAndAccount($this->packageId, 9);
        self::assertNotNull($definition);
        self::assertSame(PackageProviderSyncState::Synced, $definition->syncState());
        self::assertSame('prod_ABC', $definition->remoteId());
    }

    #[Test]
    public function editingThePackageFlipsSyncedDefinitionsToDrift(): void
    {
        $accounts = (new StubProviderAccountDirectory())->add(9, self::CLIENT, 'stripe', 'stripe');
        (new LinkPackageProviderHandler($this->packages, $this->definitions, $accounts, $this->audit, new SynchronousTransactions(), $this->clock))
            ->handle(new LinkPackageProviderCommand($this->packageId, 9, 'Pro', 'prod_ABC'));

        $update = new UpdatePackageHandler($this->packages, $this->definitions, $this->audit, new SynchronousTransactions(), $this->clock);
        self::assertTrue($update->handle(new UpdatePackageCommand($this->packageId, name: 'Pro Plus'))->isOk());

        $definition = $this->definitions->findByPackageAndAccount($this->packageId, 9);
        self::assertNotNull($definition);
        self::assertSame(PackageProviderSyncState::Drift, $definition->syncState());
    }

    #[Test]
    public function changeSyncStateMarksSyncedAndNotNeeded(): void
    {
        $accounts = (new StubProviderAccountDirectory())->add(9, self::CLIENT, 'stripe', 'stripe');
        (new LinkPackageProviderHandler($this->packages, $this->definitions, $accounts, $this->audit, new SynchronousTransactions(), $this->clock))
            ->handle(new LinkPackageProviderCommand($this->packageId, 9));
        $definition = $this->definitions->findByPackageAndAccount($this->packageId, 9);
        self::assertNotNull($definition);
        $definitionId = $definition->id();
        self::assertNotNull($definitionId);

        $handler = new ChangePackageProviderSyncStateHandler($this->definitions, $this->packages, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame('package.remote_id_required', $handler->markSynced($definitionId, '  ')->error()->code);
        self::assertTrue($handler->markSynced($definitionId, 'prod_ZZ')->isOk());
        self::assertSame(PackageProviderSyncState::Synced, $this->definitions->findById($definitionId)?->syncState());

        self::assertTrue($handler->markNotNeeded($definitionId)->isOk());
        self::assertSame(PackageProviderSyncState::NotNeeded, $this->definitions->findById($definitionId)?->syncState());
        self::assertContains('package.provider_not_needed', $this->audit->actions());
    }

    private function capabilitiesHandler(): SetPackagePurchaseCapabilitiesHandler
    {
        return new SetPackagePurchaseCapabilitiesHandler(
            $this->packages,
            $this->definitions,
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }
}
