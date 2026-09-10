<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\Idempotency\IdempotencyRecord;
use Gomrok\Shared\Application\Idempotency\IdempotencyStatus;
use Gomrok\Shared\Infrastructure\Persistence\PdoAuditLogWriter;
use Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogWriter;
use Gomrok\Shared\Infrastructure\Persistence\PdoIdempotencyStore;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use Gomrok\Tests\Support\FrozenClock;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Exercises the Phase 5 persistence adapters against real MySQL. Every test runs
 * inside a transaction that is rolled back, so nothing is left behind. Skips when
 * MySQL / the schema is not available.
 */
final class CrossCuttingWritersTest extends TestCase
{
    private PDO $pdo;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM idempotency_keys LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->clock = new FrozenClock('2026-09-08T12:00:00+00:00');
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function idempotencyStoreClaimLifecycle(): void
    {
        $store = new PdoIdempotencyStore($this->pdo, new TransactionRunner($this->pdo));
        $now = $this->clock->now();
        $expires = $now->modify('+1 day');

        $fresh = $store->claim(9001, 'key-a', 'fp1', $now, $expires);
        self::assertNull($fresh);

        $inflight = $store->claim(9001, 'key-a', 'fp1', $now, $expires);
        self::assertInstanceOf(IdempotencyRecord::class, $inflight);
        self::assertSame(IdempotencyStatus::Processing, $inflight->status);

        $mismatch = $store->claim(9001, 'key-a', 'fp2', $now, $expires);
        self::assertInstanceOf(IdempotencyRecord::class, $mismatch);
        self::assertFalse($mismatch->matchesFingerprint('fp2'));

        $store->markCompleted(9001, 'key-a', 'payment', 555, 201, $now);

        $done = $store->claim(9001, 'key-a', 'fp1', $now, $expires);
        self::assertInstanceOf(IdempotencyRecord::class, $done);
        self::assertSame(IdempotencyStatus::Done, $done->status);
        self::assertSame('payment', $done->targetType);
        self::assertSame(555, $done->targetId);
        self::assertSame(201, $done->responseStatus);
    }

    #[Test]
    public function expiredKeyIsReclaimedAndPurged(): void
    {
        $store = new PdoIdempotencyStore($this->pdo, new TransactionRunner($this->pdo));
        $past = $this->clock->now()->modify('-2 days');

        $store->claim(9002, 'key-b', 'fp', $past, $past->modify('+1 hour'));

        // now() is well past expiry → claim resets the row and reports a fresh claim
        $reclaimed = $store->claim(9002, 'key-b', 'fp-new', $this->clock->now(), $this->clock->now()->modify('+1 day'));
        self::assertNull($reclaimed);

        // and a row that is still expired is deleted by the purge
        $store->markFailed(9002, 'key-b', $this->clock->now());
        $this->pdo->prepare('UPDATE idempotency_keys SET expires_at = :e WHERE client_id = 9002')
            ->execute(['e' => $past->format('Y-m-d H:i:s')]);

        self::assertSame(1, $store->purgeExpired($this->clock->now()));
    }

    #[Test]
    public function auditWriterPersistsRedactedSnapshots(): void
    {
        (new PdoAuditLogWriter($this->pdo, $this->clock))->record(
            AuditEntry::forAdminUser(1, 9003, 'provider_config.updated')
                ->withTarget('provider_config', 12)
                ->withChange(['api_key' => 'sk_live_secret', 'mode' => 'test'], ['api_key' => 'sk_live_secret', 'mode' => 'live'])
                ->withRequest('corr-1', '203.0.113.9', 'phpunit'),
        );

        $row = $this->one('SELECT action, `before`, `after` FROM audit_logs WHERE client_id = 9003');
        self::assertSame('provider_config.updated', $row['action']);
        self::assertIsString($row['before']);
        self::assertIsString($row['after']);
        self::assertStringContainsString('[redacted]', $row['before']);
        self::assertStringNotContainsString('sk_live_secret', $row['after']);
    }

    #[Test]
    public function errorWriterPersistsFromThrowable(): void
    {
        (new PdoErrorLogWriter($this->pdo, $this->clock, new NullLogger()))->log(
            ErrorLogEntry::fromThrowable(new RuntimeException('kaboom', 500), 'job', clientId: 9004, correlationId: 'corr-2'),
        );

        $row = $this->one('SELECT source, message, exception_class, code FROM error_logs WHERE client_id = 9004');
        self::assertSame('job', $row['source']);
        self::assertSame('kaboom', $row['message']);
        self::assertSame(RuntimeException::class, $row['exception_class']);
        self::assertSame('500', $row['code']);
    }

    /**
     * @return array<string, mixed>
     */
    private function one(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        $row = $statement->fetch();
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }
}
