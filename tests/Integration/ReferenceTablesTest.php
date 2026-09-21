<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Shared\Infrastructure\Persistence\PdoReferenceCatalog;
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
        self::assertSame(19, $this->scalar('SELECT COUNT(*) FROM countries'));

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

    #[Test]
    public function listCurrenciesReturnsTheFullSortedReferenceListForTheAdminCurrencySelect(): void
    {
        $catalog = new PdoReferenceCatalog($this->pdo);

        $currencies = $catalog->listCurrencies();

        self::assertGreaterThan(150, \count($currencies));
        self::assertSame(\count($currencies), $this->scalar('SELECT COUNT(*) FROM currencies'));

        $codes = array_column($currencies, 'code');
        self::assertSame($codes, array_map('strtoupper', $codes), 'every code must already be upper-case');
        $sorted = $codes;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $codes, 'must be ordered by code, matching every <select> using it');

        $usd = current(array_filter($currencies, static fn (array $c): bool => $c['code'] === 'USD'));
        self::assertNotFalse($usd);
        self::assertSame('US Dollar', $usd['name']);
    }

    #[Test]
    public function listCountriesReturnsTheFullSortedReferenceListForTheAdminCountrySelect(): void
    {
        $catalog = new PdoReferenceCatalog($this->pdo);

        $countries = $catalog->listCountries();

        self::assertCount(19, $countries);
        self::assertCount($this->scalar('SELECT COUNT(*) FROM countries'), $countries);

        $codes = array_column($countries, 'code');
        self::assertSame($codes, array_map('strtoupper', $codes), 'every code must already be upper-case');
        $sorted = $codes;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $codes, 'must be ordered by code, matching every <select> using it');

        $tr = current(array_filter($countries, static fn (array $c): bool => $c['code'] === 'TR'));
        self::assertNotFalse($tr);
        self::assertSame('Türkiye', $tr['name']);
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
