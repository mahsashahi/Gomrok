<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Checks the Phase 4 reference tables after `composer db:setup`. Skips when
 * MySQL is unreachable or the tables have not been migrated/seeded yet.
 */
final class ReferenceTablesTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->query('SELECT 1 FROM currencies LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped(
                'MySQL not set up (' . $e->getMessage() . '). Run `docker compose up -d mysql && composer db:setup`.',
            );
        }
    }

    #[Test]
    public function currenciesAreSeededWithCorrectScales(): void
    {
        self::assertGreaterThan(150, $this->scalar('SELECT COUNT(*) FROM currencies'));

        foreach ([['USD', 840, 2], ['JPY', 392, 0], ['BHD', 48, 3]] as [$code, $numeric, $scale]) {
            $row = $this->fetchAssoc("SELECT numeric_code, minor_unit_scale FROM currencies WHERE code = '{$code}'");
            self::assertEquals($numeric, $row['numeric_code'], $code);
            self::assertEquals($scale, $row['minor_unit_scale'], $code);
        }
    }

    #[Test]
    public function countriesAreSeededAndFkToCurrencies(): void
    {
        self::assertSame(18, $this->scalar('SELECT COUNT(*) FROM countries'));

        $row = $this->fetchAssoc("SELECT name, default_currency FROM countries WHERE code = 'TR'");
        self::assertSame('Türkiye', $row['name']);
        self::assertSame('TRY', $row['default_currency']);

        self::assertSame(0, $this->scalar(
            'SELECT COUNT(*) FROM countries c
             LEFT JOIN currencies cur ON cur.code = c.default_currency
             WHERE cur.code IS NULL',
        ));
    }

    #[Test]
    public function providerTypesAreSeededWithFlags(): void
    {
        self::assertSame(4, $this->scalar('SELECT COUNT(*) FROM provider_types'));

        $stripe = $this->fetchAssoc("SELECT requires_registration, api_capable FROM provider_types WHERE code = 'stripe'");
        self::assertEquals(1, $stripe['requires_registration']);
        self::assertEquals(1, $stripe['api_capable']);

        $ziraat = $this->fetchAssoc("SELECT requires_registration, api_capable FROM provider_types WHERE code = 'ziraat'");
        self::assertEquals(0, $ziraat['requires_registration']);
        self::assertEquals(0, $ziraat['api_capable']);
    }

    private function scalar(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array<string, string>
     */
    private function fetchAssoc(string $sql): array
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        $row = $statement->fetch();
        self::assertIsArray($row);

        /** @var array<string, string> $row */
        return $row;
    }
}
