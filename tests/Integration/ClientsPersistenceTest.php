<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use DateTimeImmutable;
use Gomrok\Config\Settings;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientSlug;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Clients\Infrastructure\PdoClientApiKeyRepository;
use Gomrok\Modules\Clients\Infrastructure\PdoClientDirectory;
use Gomrok\Modules\Clients\Infrastructure\PdoClientRepository;
use Gomrok\Shared\Domain\CountryCode;
use Gomrok\Shared\Domain\Currency;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the Clients module adapters and the schema's constraints against
 * real MySQL. Each test runs in a rolled-back transaction. Skips without MySQL.
 */
final class ClientsPersistenceTest extends TestCase
{
    private PDO $pdo;
    private PdoClientRepository $clients;
    private PdoClientApiKeyRepository $apiKeys;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM clients LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->clients = new PdoClientRepository($this->pdo);
        $this->apiKeys = new PdoClientApiKeyRepository($this->pdo);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function clientRoundTripsWithEndpoints(): void
    {
        $client = $this->newClient('rt-client');
        $client->setEndpoint(EndpointPurpose::PaymentStatus, 'https://rt.example/hook', $this->now());
        $this->clients->save($client);

        $id = $client->id();
        self::assertNotNull($id);

        $reloaded = $this->clients->findBySlug('rt-client');
        self::assertNotNull($reloaded);
        self::assertSame($id, $reloaded->id());
        self::assertSame('EUR', $reloaded->defaultCurrency()->code());
        self::assertSame('DE', $reloaded->defaultCountry()?->value);
        self::assertCount(1, $reloaded->endpoints());
        self::assertSame('https://rt.example/hook', $reloaded->endpoints()[0]->url());

        // update: drop the endpoint, rename
        $reloaded->removeEndpoint(EndpointPurpose::PaymentStatus, $this->now());
        $reloaded->rename('Renamed', $this->now());
        $this->clients->save($reloaded);

        $again = $this->clients->findById($id);
        self::assertNotNull($again);
        self::assertSame('Renamed', $again->name());
        self::assertCount(0, $again->endpoints());
    }

    #[Test]
    public function apiKeyRoundTripsAndCountsActive(): void
    {
        $client = $this->newClient('key-client');
        $this->clients->save($client);
        $clientId = $client->id();
        self::assertNotNull($clientId);

        $key = ClientApiKey::issue($clientId, 'aaaa0000bbbb1111', hash('sha256', 'sekret'), ApiKeyPrefix::Live, 'kret', 'ci', $this->now());
        $this->apiKeys->save($key);

        $found = $this->apiKeys->findByKeyId('aaaa0000bbbb1111');
        self::assertNotNull($found);
        self::assertTrue($found->matchesSecret('sekret'));
        self::assertSame(1, $this->apiKeys->countActiveForClient($clientId));

        $found->revoke(1, $this->now());
        $this->apiKeys->save($found);
        self::assertSame(0, $this->apiKeys->countActiveForClient($clientId));
    }

    #[Test]
    public function directoryReturnsASnapshot(): void
    {
        $client = $this->newClient('dir-client');
        $this->clients->save($client);

        $directory = new PdoClientDirectory($this->pdo);
        $snapshot = $directory->findBySlug('dir-client');

        self::assertNotNull($snapshot);
        self::assertSame($client->id(), $snapshot->id);
        self::assertSame('dir-client', $snapshot->slug);
        self::assertTrue($snapshot->isActive());
    }

    #[Test]
    public function slugIsUniqueAtTheDatabase(): void
    {
        $this->clients->save($this->newClient('dupe'));

        $this->expectException(PDOException::class);
        $this->pdo->prepare(
            'INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES (\'dupe\', \'x\', \'active\', \'EUR\', \'UTC\', \'s\', :now)',
        )->execute(['now' => $this->now()->format('Y-m-d H:i:s')]);
    }

    #[Test]
    public function apiKeyRequiresAnExistingClient(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->prepare(
            'INSERT INTO client_api_keys (client_id, key_id, secret_hash, prefix, last_four, status, created_at)
             VALUES (2147000000, \'x000000000000000\', :h, \'gk_live\', \'aaaa\', \'active\', :now)',
        )->execute(['h' => str_repeat('0', 64), 'now' => $this->now()->format('Y-m-d H:i:s')]);
    }

    #[Test]
    public function idempotencyKeysNowRequireAnExistingClient(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->prepare(
            'INSERT INTO idempotency_keys (client_id, idempotency_key, request_fingerprint, status, created_at, expires_at)
             VALUES (2147000000, \'k\', :fp, \'processing\', :now, :now)',
        )->execute(['fp' => str_repeat('0', 64), 'now' => $this->now()->format('Y-m-d H:i:s')]);
    }

    private function newClient(string $slug): Client
    {
        return Client::register(
            ClientSlug::of($slug),
            ucfirst($slug),
            Currency::of('EUR'),
            CountryCode::of('DE'),
            'UTC',
            'signing-secret',
            $this->now(),
        );
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-08T12:00:00+00:00');
    }
}
