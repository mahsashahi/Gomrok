<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupCommand;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupHandler;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupCommand;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupHandler;
use Gomrok\Modules\Providers\Application\CreateProviderGroup\CreateProviderGroupResult;
use Gomrok\Modules\Providers\Application\Routing\ProviderRouter;
use Gomrok\Modules\Providers\Application\Routing\RoutingDecision;
use Gomrok\Modules\Providers\Application\Routing\RoutingRequest;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\ProviderGroupAccountInput;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsCommand;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsHandler;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountDirectory;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderGroupRepository;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderTypeDeclarations;
use Gomrok\Shared\Infrastructure\Persistence\PdoReferenceCatalog;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use Gomrok\Shared\Infrastructure\SystemClock;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Phase 10 persistence: a provider group + its child rows round-trip through the
 * MySQL repository, and the router resolves against the stored data. Each test
 * rolls back. Skips without MySQL / the seeded schema.
 */
final class ProviderGroupsPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $clientId;
    private int $stripeAccountId;
    private int $ziraatAccountId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM provider_groups LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('pg-test-client', 'PG Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();

        $this->stripeAccountId = $this->account('stripe', 'pg-stripe', [], $now);
        $this->ziraatAccountId = $this->account('ziraat', 'pg-ziraat', ['TR'], $now);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function groupRoundTripsAndTheRouterResolvesAgainstIt(): void
    {
        $repo = new PdoProviderGroupRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $clock = new SystemClock();
        $reference = new PdoReferenceCatalog($this->pdo);
        $directory = new PdoProviderAccountDirectory($this->pdo);
        $audit = new RecordingAuditLogWriter();

        $create = new CreateProviderGroupHandler($repo, new StubClientDirectory($this->clientId, 'pg-test-client'), $reference, $audit, $transactions, $clock);
        $turkey = $create->handle(new CreateProviderGroupCommand($this->clientId, 'Turkey'));
        self::assertTrue($turkey->isOk());
        $turkeyPayload = $turkey->value();
        self::assertInstanceOf(CreateProviderGroupResult::class, $turkeyPayload);
        $turkeyId = $turkeyPayload->groupId;

        $default = $create->handle(new CreateProviderGroupCommand($this->clientId, 'Default', isDefault: true));
        self::assertTrue($default->isOk());
        $defaultPayload = $default->value();
        self::assertInstanceOf(CreateProviderGroupResult::class, $defaultPayload);

        $configure = new ConfigureProviderGroupHandler($repo, $reference, $audit, $transactions, $clock);
        self::assertTrue($configure->handle(new ConfigureProviderGroupCommand($turkeyId, countries: ['TR'], purchaseTypes: ['one_time_payment']))->isOk());
        self::assertTrue($configure->handle(new ConfigureProviderGroupCommand($defaultPayload->groupId, purchaseTypes: ['one_time_payment', 'subscription']))->isOk());

        $setAccounts = new SetProviderGroupAccountsHandler($repo, $directory, $audit, $transactions, $clock);
        self::assertTrue($setAccounts->handle(new SetProviderGroupAccountsCommand($turkeyId, [new ProviderGroupAccountInput($this->ziraatAccountId, 0)]))->isOk());
        self::assertTrue($setAccounts->handle(new SetProviderGroupAccountsCommand($defaultPayload->groupId, [new ProviderGroupAccountInput($this->stripeAccountId, 0)]))->isOk());

        // reload round-trip
        $stored = $repo->findById($turkeyId);
        self::assertNotNull($stored);
        self::assertSame(['TR'], $stored->countryCodes());
        self::assertCount(1, $stored->accounts());

        // router resolves against the persisted data
        $router = new ProviderRouter($repo, $directory, new PdoProviderTypeDeclarations($this->pdo), new NullLogger());

        $tr = $router->route(new RoutingRequest($this->clientId, 'TR', 'TRY', PurchaseType::OneTimePayment, ProviderAccountMode::Test));
        self::assertTrue($tr->isOk());
        $trDecision = $tr->value();
        self::assertInstanceOf(RoutingDecision::class, $trDecision);
        self::assertSame('pg-ziraat', $trDecision->chosen()->slug);

        $trSub = $router->route(new RoutingRequest($this->clientId, 'TR', 'TRY', PurchaseType::Subscription, ProviderAccountMode::Test));
        self::assertTrue($trSub->isErr());
        self::assertSame('provider_routing.purchase_type_not_enabled', $trSub->error()->code);

        $fr = $router->route(new RoutingRequest($this->clientId, 'FR', 'EUR', PurchaseType::Subscription, ProviderAccountMode::Test));
        self::assertTrue($fr->isOk());
        $frDecision = $fr->value();
        self::assertInstanceOf(RoutingDecision::class, $frDecision);
        self::assertTrue($frDecision->groupIsDefault);
    }

    /**
     * @param list<string> $countries
     */
    private function account(string $providerType, string $slug, array $countries, string $now): int
    {
        $typeStatement = $this->pdo->prepare('SELECT id FROM provider_types WHERE code = :code');
        $typeStatement->execute(['code' => $providerType]);
        $ptId = (int) $typeStatement->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO provider_accounts
                (client_id, provider_type_id, slug, name, mode, status, secret_ciphertext, secret_last_four, created_at)
             VALUES (:c, :pt, :slug, :slug, 'test', 'active', 'x', '0000', :now)",
        )->execute(['c' => $this->clientId, 'pt' => $ptId, 'slug' => $slug, 'now' => $now]);
        $id = (int) $this->pdo->lastInsertId();

        foreach ($countries as $country) {
            $this->pdo->prepare(
                'INSERT INTO provider_account_countries (provider_account_id, country_code, created_at) VALUES (:id, :code, :now)',
            )->execute(['id' => $id, 'code' => $country, 'now' => $now]);
        }

        return $id;
    }
}
