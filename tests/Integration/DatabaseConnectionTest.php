<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use DI\ContainerBuilder;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the real PDO wiring against the MySQL from docker-compose.
 * Run with: composer test:integration (needs `docker compose up -d mysql`).
 * Skips (does not fail) when no MySQL is reachable.
 */
final class DatabaseConnectionTest extends TestCase
{
    #[Test]
    public function containerBuildsAWorkingPdoConnection(): void
    {
        /** @var array<string, mixed> $definitions */
        $definitions = require \dirname(__DIR__, 2) . '/src/Config/container.php';

        $container = (new ContainerBuilder())->addDefinitions($definitions)->build();

        try {
            $pdo = $container->get(PDO::class);
            self::assertInstanceOf(PDO::class, $pdo);

            $statement = $pdo->query('SELECT 1 AS one');
            self::assertNotFalse($statement);
            $row = $statement->fetch();
        } catch (PDOException $e) {
            self::markTestSkipped(
                'MySQL not reachable (' . $e->getMessage() . '). Run `docker compose up -d mysql`.',
            );
        }

        self::assertIsArray($row);
        self::assertArrayHasKey('one', $row);
        self::assertEquals(1, $row['one']);
    }
}
