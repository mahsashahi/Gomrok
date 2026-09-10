<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use DateTimeImmutable;
use Gomrok\Config\Settings;
use Gomrok\Modules\Clients\Application\Authenticate\AuthAttempt;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\AuthFailureReason;
use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientSlug;
use Gomrok\Modules\Clients\Infrastructure\PdoAuthAttemptLog;
use Gomrok\Modules\Clients\Infrastructure\PdoClientApiKeyRepository;
use Gomrok\Modules\Clients\Infrastructure\PdoClientRepository;
use Gomrok\Shared\Domain\CountryCode;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Http\AuthRequestMeta;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 7 persistence: `client_auth_attempts` inserts + `touchLastUsed`. Each
 * test runs in a rolled-back transaction. Skips without MySQL / schema.
 */
final class AuthAttemptsPersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM client_auth_attempts LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function recordsFailureAndSuccessRows(): void
    {
        $log = new PdoAuthAttemptLog($this->pdo);
        $now = new DateTimeImmutable('2026-09-08T12:00:00+00:00');
        $meta = new AuthRequestMeta('203.0.113.9', 'phpunit', 'corr-1');

        $log->record(AuthAttempt::failure(AuthFailureReason::InvalidSecret, 'abc0000000000000', null, $meta), $now);

        $client = Client::register(ClientSlug::of('attempt-c'), 'Attempt', Currency::of('EUR'), CountryCode::of('DE'), 'UTC', 's', $now);
        (new PdoClientRepository($this->pdo))->save($client);
        $clientId = $client->id();
        self::assertNotNull($clientId);

        $log->record(AuthAttempt::success('abc0000000000000', $clientId, $meta), $now);

        $statement = $this->pdo->query(
            "SELECT outcome, reason, client_id FROM client_auth_attempts WHERE key_id = 'abc0000000000000' ORDER BY id",
        );
        self::assertNotFalse($statement);

        $rows = [];
        while (($row = $statement->fetch()) !== false) {
            self::assertIsArray($row);
            $rows[] = [
                'outcome' => Row::str($row['outcome'] ?? null),
                'reason' => Row::str($row['reason'] ?? null),
                'client_id' => Row::nullableInt($row['client_id'] ?? null),
            ];
        }

        self::assertSame(
            [
                ['outcome' => 'failure', 'reason' => 'invalid_secret', 'client_id' => null],
                ['outcome' => 'success', 'reason' => 'ok', 'client_id' => $clientId],
            ],
            $rows,
        );
    }

    #[Test]
    public function touchLastUsedUpdatesTheColumn(): void
    {
        $now = new DateTimeImmutable('2026-09-08T12:00:00+00:00');
        $client = Client::register(ClientSlug::of('touch-c'), 'Touch', Currency::of('EUR'), CountryCode::of('DE'), 'UTC', 's', $now);
        (new PdoClientRepository($this->pdo))->save($client);
        $clientId = $client->id();
        self::assertNotNull($clientId);

        $keys = new PdoClientApiKeyRepository($this->pdo);
        $key = ClientApiKey::issue($clientId, 'touch000000000000', hash('sha256', 's'), ApiKeyPrefix::Live, 'xxxx', null, $now);
        $keys->save($key);
        $keyId = $key->id();
        self::assertNotNull($keyId);

        $keys->touchLastUsed($keyId, $now->modify('+1 hour'));

        $reloaded = $keys->findByKeyId('touch000000000000');
        self::assertNotNull($reloaded);
        self::assertEquals($now->modify('+1 hour'), $reloaded->lastUsedAt());
    }
}
