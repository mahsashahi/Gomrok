<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageCommand;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageHandler;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageResult;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderCommand;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderHandler;
use Gomrok\Modules\Packages\Application\PackageCatalog;
use Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver;
use Gomrok\Modules\Packages\Application\ResolvedPackage;
use Gomrok\Modules\Packages\Application\SetPackageAvailability\SetPackageAvailabilityCommand;
use Gomrok\Modules\Packages\Application\SetPackageAvailability\SetPackageAvailabilityHandler;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\PurchaseCapabilityInput;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesCommand;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesHandler;
use Gomrok\Modules\Packages\Domain\PackageProviderSyncState;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageRepository;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountDirectory;
use Gomrok\Shared\Infrastructure\Persistence\PdoReferenceCatalog;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use Gomrok\Shared\Infrastructure\SystemClock;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11 persistence: a package + its availability child rows round-trip
 * through the MySQL repository, and {@see PackageCatalog} resolves against the
 * stored data. Each test rolls back. Skips without MySQL / the seeded schema.
 */
final class PackagesPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $clientId;
    private int $stripeAccountId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM packages LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('pkg-test-client', 'PKG Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();

        $ptStatement = $this->pdo->prepare('SELECT id FROM provider_types WHERE code = :code');
        $ptStatement->execute(['code' => 'stripe']);
        $stripeTypeId = (int) $ptStatement->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO provider_accounts
                (client_id, provider_type_id, slug, name, mode, status, secret_ciphertext, secret_last_four, created_at)
             VALUES (:c, :pt, 'pkg-stripe', 'pkg-stripe', 'test', 'active', 'x', '0000', :now)",
        )->execute(['c' => $this->clientId, 'pt' => $stripeTypeId, 'now' => $now]);
        $this->stripeAccountId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function packageRoundTripsAndTheCatalogResolvesAgainstIt(): void
    {
        $repo = new PdoPackageRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $clock = new SystemClock();
        $reference = new PdoReferenceCatalog($this->pdo);
        $directory = new PdoProviderAccountDirectory($this->pdo);
        $audit = new RecordingAuditLogWriter();

        $create = new CreatePackageHandler($repo, new StubClientDirectory($this->clientId, 'pkg-test-client'), $audit, $transactions, $clock);
        $starter = $create->handle(new CreatePackageCommand($this->clientId, 'starter', 'Starter'));
        $pro = $create->handle(new CreatePackageCommand($this->clientId, 'pro', 'Pro', 'Full plan', ['tier' => 2]));
        self::assertTrue($starter->isOk());
        self::assertTrue($pro->isOk());
        $proPayload = $pro->value();
        self::assertInstanceOf(CreatePackageResult::class, $proPayload);

        $definitions = new PdoPackageProviderDefinitionRepository($this->pdo);

        $setCapabilities = new SetPackagePurchaseCapabilitiesHandler($repo, $definitions, $audit, $transactions, $clock);
        foreach (['starter', 'pro'] as $code) {
            $found = $repo->findByClientAndCode($this->clientId, $code);
            self::assertNotNull($found);
            $id = $found->id();
            self::assertNotNull($id);
            self::assertTrue($setCapabilities->handle(new SetPackagePurchaseCapabilitiesCommand(
                $id,
                [new PurchaseCapabilityInput('one_time_payment')],
            ))->isOk());
        }

        $setAvailability = new SetPackageAvailabilityHandler($repo, $reference, $directory, $definitions, $audit, $transactions, $clock);
        self::assertTrue($setAvailability->handle(new SetPackageAvailabilityCommand(
            $proPayload->packageId,
            countries: ['DE'],
            currencies: ['EUR'],
            providerAccountIds: [$this->stripeAccountId],
        ))->isOk());

        // reload round-trip
        $stored = $repo->findByClientAndCode($this->clientId, 'pro');
        self::assertNotNull($stored);
        self::assertSame(['DE'], $stored->countryCodes());
        self::assertSame(['EUR'], $stored->currencyCodes());
        self::assertSame([$this->stripeAccountId], $stored->providerAccountIds());
        self::assertSame(['tier' => 2], $stored->metadata());
        self::assertCount(1, $stored->purchaseCapabilities());

        // catalogue resolution
        $catalog = new PackageCatalog($repo, $directory, new PackagePurchaseCapabilityResolver($repo));

        $de = $catalog->resolve($this->clientId, 'DE', 'EUR');
        $deCodes = array_map(static fn (ResolvedPackage $p): string => $p->code, $de);
        sort($deCodes);
        self::assertSame(['pro', 'starter'], $deCodes);

        $fr = $catalog->resolve($this->clientId, 'FR', 'EUR');
        self::assertSame(['starter'], array_map(static fn (ResolvedPackage $p): string => $p->code, $fr));
    }

    #[Test]
    public function providerDefinitionRoundTripsAndTheDriftSweepWorks(): void
    {
        $repo = new PdoPackageRepository($this->pdo);
        $definitions = new PdoPackageProviderDefinitionRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $clock = new SystemClock();
        $directory = new PdoProviderAccountDirectory($this->pdo);
        $audit = new RecordingAuditLogWriter();
        $clientDirectory = new StubClientDirectory($this->clientId, 'pkg-test-client');

        $create = new CreatePackageHandler($repo, $clientDirectory, $audit, $transactions, $clock);
        $created = $create->handle(new CreatePackageCommand($this->clientId, 'pro', 'Pro'));
        self::assertTrue($created->isOk());
        $payload = $created->value();
        self::assertInstanceOf(CreatePackageResult::class, $payload);
        $packageId = $payload->packageId;

        (new SetPackagePurchaseCapabilitiesHandler($repo, $definitions, $audit, $transactions, $clock))
            ->handle(new SetPackagePurchaseCapabilitiesCommand($packageId, [new PurchaseCapabilityInput('one_time_payment')]));

        $link = new LinkPackageProviderHandler($repo, $definitions, $directory, $audit, $transactions, $clock);
        self::assertTrue($link->handle(new LinkPackageProviderCommand($packageId, $this->stripeAccountId, 'Pro (Stripe)', 'prod_TEST'))->isOk());

        $stored = $definitions->findByPackageAndAccount($packageId, $this->stripeAccountId);
        self::assertNotNull($stored);
        self::assertSame(PackageProviderSyncState::Synced, $stored->syncState());
        self::assertSame('prod_TEST', $stored->remoteId());

        // editing the package drifts the synced definition
        (new SetPackagePurchaseCapabilitiesHandler($repo, $definitions, $audit, $transactions, $clock))
            ->handle(new SetPackagePurchaseCapabilitiesCommand($packageId, [
                new PurchaseCapabilityInput('one_time_payment'),
                new PurchaseCapabilityInput('subscription'),
            ]));

        $afterEdit = $definitions->findByPackageAndAccount($packageId, $this->stripeAccountId);
        self::assertNotNull($afterEdit);
        self::assertSame(PackageProviderSyncState::Drift, $afterEdit->syncState());
    }
}
