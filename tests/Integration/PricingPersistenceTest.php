<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageDirectory;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupCommand;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupResult;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceSource;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Application\SetClientExchangeRate\SetClientExchangeRateCommand;
use Gomrok\Modules\Pricing\Application\SetClientExchangeRate\SetClientExchangeRateHandler;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceCommand;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceHandler;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupCountries\SetPricingGroupCountriesHandler;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageHandler;
use Gomrok\Modules\Pricing\Infrastructure\PdoClientExchangeRateRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoDefaultPackagePriceRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Infrastructure\PdoPricingGroupRepository;
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
 * Phase 13 persistence: pricing groups + default prices + rates round-trip, and
 * {@see PriceResolver} resolves against the stored data (priority match,
 * override, cross-currency conversion). Each test rolls back.
 */
final class PricingPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $clientId;
    private int $packageId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM pricing_groups LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('pr-test-client', 'PR Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO packages (client_id, code, name, status, created_at) VALUES (:c, 'pro', 'Pro', 'active', :now)",
        )->execute(['c' => $this->clientId, 'now' => $now]);
        $this->packageId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function groupsRoundTripAndTheResolverPricesAgainstThem(): void
    {
        $groups = new PdoPricingGroupRepository($this->pdo);
        $rows = new PdoPricingGroupPackageRepository($this->pdo);
        $defaults = new PdoDefaultPackagePriceRepository($this->pdo);
        $rates = new PdoClientExchangeRateRepository($this->pdo);
        $packages = new PdoPackageDirectory($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $clock = new SystemClock();
        $reference = new PdoReferenceCatalog($this->pdo);
        $audit = new RecordingAuditLogWriter();
        $clients = new StubClientDirectory($this->clientId, 'pr-test-client');

        $create = new CreatePricingGroupHandler($groups, $clients, $reference, $audit, $transactions, $clock);
        $default = $create->handle(new CreatePricingGroupCommand($this->clientId, 'Default', 'EUR', isDefault: true));
        $dach = $create->handle(new CreatePricingGroupCommand($this->clientId, 'DACH', 'EUR', slug: 'dach', priority: 1));
        $us = $create->handle(new CreatePricingGroupCommand($this->clientId, 'US', 'USD', slug: 'us', priority: 2));
        self::assertTrue($default->isOk() && $dach->isOk() && $us->isOk());
        $dachId = $this->idOf($dach);
        $usId = $this->idOf($us);

        $setCountries = new SetPricingGroupCountriesHandler($groups, $reference, $audit, $transactions, $clock);
        self::assertTrue($setCountries->handle(new SetPricingGroupCountriesCommand($dachId, ['DE']))->isOk());
        self::assertTrue($setCountries->handle(new SetPricingGroupCountriesCommand($usId, ['US']))->isOk());

        $setPrice = new SetDefaultPackagePriceHandler($defaults, $packages, $reference, $audit, $transactions);
        self::assertTrue($setPrice->handle(new SetDefaultPackagePriceCommand($this->packageId, 2900, 'EUR'))->isOk());

        $setRate = new SetClientExchangeRateHandler($rates, $clients, $reference, $audit, $transactions, $clock);
        self::assertTrue($setRate->handle(new SetClientExchangeRateCommand($this->clientId, 'EUR', 'USD', '1.10', '2026-01-01T00:00:00Z'))->isOk());

        $setRow = new SetPricingGroupPackageHandler($rows, $groups, $packages, $audit, $transactions, $clock);
        self::assertTrue($setRow->handle(new SetPricingGroupPackageCommand($dachId, $this->packageId, status: 'override', amountMinor: 2400, currency: 'EUR'))->isOk());

        // reload round-trip
        $stored = $groups->findByClientAndSlug($this->clientId, 'dach');
        self::assertNotNull($stored);
        self::assertSame(['DE'], $stored->countryCodes());
        self::assertSame(1, $stored->priority());

        $resolver = new PriceResolver(
            $groups,
            $rows,
            $defaults,
            $rates,
            $packages,
            new \Gomrok\Modules\Pricing\Application\PriceRuleResolver(new \Gomrok\Modules\Pricing\Infrastructure\PdoPriceRuleRepository($this->pdo)),
            $clock,
        );

        $de = $this->price($resolver->resolve($this->clientId, $this->packageId, 'DE'));
        self::assertSame('dach', $de->pricingGroupSlug);
        self::assertSame(2400, $de->amountMinor);
        self::assertSame(PriceSource::GroupOverride, $de->source);

        $us2 = $this->price($resolver->resolve($this->clientId, $this->packageId, 'US'));
        self::assertSame('USD', $us2->currencyCode);
        self::assertSame(PriceSource::Converted, $us2->source);
        self::assertSame(3190, $us2->amountMinor);

        $fr = $this->price($resolver->resolve($this->clientId, $this->packageId, 'FR'));
        self::assertTrue($fr->pricingGroupIsDefault);
        self::assertSame(2900, $fr->amountMinor);
    }

    private function idOf(\Gomrok\Shared\Domain\Result $result): int
    {
        $payload = $result->value();
        self::assertInstanceOf(CreatePricingGroupResult::class, $payload);

        return $payload->groupId;
    }

    private function price(\Gomrok\Shared\Domain\Result $result): ResolvedPrice
    {
        self::assertTrue($result->isOk(), $result->isErr() ? $result->error()->code : '');
        $price = $result->value();
        self::assertInstanceOf(ResolvedPrice::class, $price);

        return $price;
    }
}
