<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises TransactionRunner against the docker-compose MySQL. Skips when no
 * MySQL is reachable.
 */
final class TransactionRunnerTest extends TestCase
{
    private PDO $pdo;
    private TransactionRunner $runner;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL not reachable (' . $e->getMessage() . ').');
        }

        $this->pdo->exec('CREATE TEMPORARY TABLE tx_probe (n INT NOT NULL)');
        $this->runner = new TransactionRunner($this->pdo);
    }

    #[Test]
    public function commitsOnSuccess(): void
    {
        $rows = $this->runner->run(function (): int {
            $this->pdo->exec('INSERT INTO tx_probe (n) VALUES (1)');

            return $this->probeCount();
        });

        self::assertSame(1, $rows);
        self::assertSame(1, $this->probeCount());
    }

    #[Test]
    public function rollsBackOnException(): void
    {
        try {
            $this->runner->run(function (): void {
                $this->pdo->exec('INSERT INTO tx_probe (n) VALUES (2)');

                throw new RuntimeException('nope');
            });
        } catch (RuntimeException) {
            // expected — the original error propagates out of run()
        }

        // the insert was rolled back
        self::assertSame(0, $this->probeCount());
    }

    #[Test]
    public function nestedCallJoinsTheOuterTransaction(): void
    {
        $this->runner->run(function (): void {
            $this->pdo->exec('INSERT INTO tx_probe (n) VALUES (3)');
            $this->runner->run(function (): void {
                $this->pdo->exec('INSERT INTO tx_probe (n) VALUES (4)');
            });
        });

        self::assertSame(2, $this->probeCount());
    }

    private function probeCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM tx_probe');
        self::assertNotFalse($statement);

        return max(0, (int) $statement->fetchColumn());
    }
}
