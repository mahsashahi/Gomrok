<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountCommand;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountHandler;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountCredentials;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountDirectory;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountRepository;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderCatalog;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderTypeDeclarations;
use Gomrok\Shared\Infrastructure\Crypto\SodiumSecretCipher;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use Gomrok\Shared\Infrastructure\SystemClock;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 9 persistence: secret round-trip + masking, multiple accounts of one
 * type per client, endpoint token reverse-lookup. Each test rolls back. Skips
 * without MySQL / the seeded schema.
 */
final class ProviderAccountsPersistenceTest extends TestCase
{
    private PDO $pdo;
    private SodiumSecretCipher $cipher;
    private int $clientId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM provider_accounts LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->cipher = SodiumSecretCipher::fromBase64Key('KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKio=');
        $this->pdo->beginTransaction();

        // a real client row for the FK
        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('pa-test-client', 'PA Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => gmdate('Y-m-d H:i:s')]);
        $this->clientId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function secretRoundTripsAndIsMasked(): void
    {
        $handler = $this->createHandler();

        $result = $handler->handle(new CreateProviderAccountCommand(
            $this->clientId,
            'stripe',
            'live',
            'Live',
            'sk_live_realsecret9876',
            publicKey: 'pk_live_x',
            countries: ['DE', 'NL'],
            methods: ['card'],
        ));
        self::assertTrue($result->isOk());

        // stored ciphertext is not the plaintext
        $stored = $this->pdo->query("SELECT secret_ciphertext, secret_last_four FROM provider_accounts WHERE client_id = {$this->clientId}");
        self::assertNotFalse($stored);
        $row = $stored->fetch();
        self::assertIsArray($row);
        self::assertStringNotContainsString('sk_live_realsecret9876', Row::str($row['secret_ciphertext'] ?? ''));
        self::assertSame('9876', Row::str($row['secret_last_four'] ?? ''));

        $account = (new PdoProviderAccountRepository($this->pdo))->findByClientAndSlug($this->clientId, 'stripe-live');
        self::assertNotNull($account);
        $accountId = $account->id();
        self::assertNotNull($accountId);

        // the decrypt path returns the original
        $secret = (new PdoProviderAccountCredentials($this->pdo, $this->cipher))->secretFor($accountId);
        self::assertSame('sk_live_realsecret9876', $secret);

        // the read model masks
        $summary = (new PdoProviderAccountDirectory($this->pdo))->find($this->clientId, 'stripe-live');
        self::assertNotNull($summary);
        self::assertSame('••••9876', $summary->maskedSecret());
        self::assertSame(['DE', 'NL'], $summary->countries);
        self::assertSame(['card'], $summary->methods);
    }

    #[Test]
    public function multipleAccountsOfOneTypePerClient(): void
    {
        $handler = $this->createHandler();
        $handler->handle(new CreateProviderAccountCommand($this->clientId, 'stripe', 'live', 'Live', 'sk_live_a', countries: ['DE']));
        $handler->handle(new CreateProviderAccountCommand($this->clientId, 'stripe', 'test', 'Test', 'sk_test_b', countries: ['DE']));

        $directory = new PdoProviderAccountDirectory($this->pdo);

        self::assertCount(2, $directory->forClient($this->clientId));
        self::assertCount(1, $directory->candidates($this->clientId, 'stripe', ProviderAccountMode::Live));
        self::assertCount(1, $directory->candidates($this->clientId, 'stripe', ProviderAccountMode::Test));
        self::assertCount(0, $directory->candidates($this->clientId, 'paypal', ProviderAccountMode::Live));
    }

    #[Test]
    public function endpointTokenReverseLookup(): void
    {
        $handler = $this->createHandler();
        $handler->handle(new CreateProviderAccountCommand($this->clientId, 'stripe', 'live', 'Live', 'sk_live_x'));

        $repo = new PdoProviderAccountRepository($this->pdo);
        $account = $repo->findByClientAndSlug($this->clientId, 'stripe-live');
        self::assertNotNull($account);

        $this->pdo->prepare(
            "INSERT INTO provider_account_endpoints (provider_account_id, kind, token, is_active, created_at)
             VALUES (:id, 'webhook', 'whk_lookup_me', 1, :now)",
        )->execute(['id' => $account->id(), 'now' => gmdate('Y-m-d H:i:s')]);

        $found = $repo->findByEndpointToken('whk_lookup_me');
        self::assertNotNull($found);
        self::assertSame($account->id(), $found->id());
        self::assertNull($repo->findByEndpointToken('whk_nope'));
    }

    private function createHandler(): CreateProviderAccountHandler
    {
        return new CreateProviderAccountHandler(
            new PdoProviderAccountRepository($this->pdo),
            new StubClientDirectory(clientId: $this->clientId),
            new PdoProviderCatalog($this->pdo, new PdoProviderTypeDeclarations($this->pdo)),
            new InMemoryReferenceCatalog(),
            $this->cipher,
            new RecordingAuditLogWriter(),
            new TransactionRunner($this->pdo),
            new SystemClock(),
        );
    }
}
