<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use PDO;
use PDOException;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Guards the exact regression that left the admin UI's currency/country
 * dropdowns empty on a real deployment: an operator ran only
 * `phinx migrate` (per the old deployment runbook's advice to avoid the seed
 * step in production) and never populated the `currencies`/`countries`/
 * `provider_types` reference tables at all.
 *
 * Unlike {@see MigrationRoundTripTest}, this never rolls back or re-migrates
 * the schema — it only calls `Manager::seed()` (== bare `phinx seed:run`,
 * `composer seed`'s target) against the already-migrated shared dev database,
 * with `APP_ENV` forced to `production` for the duration of the call. That is
 * safe to run against real data because:
 *
 * - `CurrenciesSeeder`, `CountriesSeeder`, `ProviderTypesSeeder`,
 *   `ProviderCapabilitiesSeeder`, `ProviderTypeDeclarationsSeeder` are pure
 *   reference-data upserts (`ON DUPLICATE KEY UPDATE` on a natural key) — safe
 *   and idempotent in every environment.
 * - `ClientsSeeder`, `PackagesSeeder`, `PricingSeeder`,
 *   `ProviderAccountsSeeder`, `ProviderGroupsSeeder`, `VouchersSeeder` are all
 *   explicitly gated to no-op outside `APP_ENV` `local`/`testing` — under a
 *   forced `production`, every one of them is a no-op.
 * - No seeder ever touches `admin_users` (it has no seeder at all —
 *   `bin/CreateAdminUser.php` is the only way a row gets created there).
 *
 * Skips when MySQL is unreachable.
 */
final class ProductionSeedSafetyTest extends TestCase
{
    private PDO $pdo;
    private Manager $manager;

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

        /** @var array<string, mixed> $settings */
        $settings = require \dirname(__DIR__, 2) . '/phinx.php';
        $this->manager = new Manager(new Config($settings), new StringInput(' '), new NullOutput());
    }

    #[Test]
    public function seedingUnderProductionPopulatesReferenceDataWithoutTouchingClientOrAdminData(): void
    {
        $before = $this->snapshot();

        $this->seedAsProduction();

        $after = $this->snapshot();

        self::assertGreaterThan(150, $after['currencies'], 'the full ISO 4217 set must be present');
        self::assertSame(19, $after['countries']);
        self::assertSame(4, $after['provider_types']);

        // The exact regression this test guards: these must never come back
        // empty after a production-mode seed run.
        self::assertGreaterThan(0, $after['currencies']);
        self::assertGreaterThan(0, $after['countries']);

        // A production-mode seed run must never create demo data or touch
        // real admin/client rows — every demo seeder is env-gated to a no-op.
        self::assertSame($before['clients'], $after['clients'], 'no demo client must be created under APP_ENV=production');
        self::assertSame($before['admin_users'], $after['admin_users'], 'no seeder may touch admin_users');
    }

    #[Test]
    public function seedingUnderProductionTwiceInARowIsIdempotent(): void
    {
        $this->seedAsProduction();
        $firstRun = $this->snapshot();

        $this->seedAsProduction();
        $secondRun = $this->snapshot();

        self::assertSame($firstRun, $secondRun, 'a second production-mode seed run must not change row counts');
    }

    /**
     * Runs the full seed batch (`phinx seed:run`, no `-s` filter — the same
     * thing `composer seed` and half of `composer db:setup` run) with
     * `APP_ENV` forced to `production`, restoring the previous value
     * afterwards regardless of outcome.
     */
    private function seedAsProduction(): void
    {
        $previous = getenv('APP_ENV');
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';

        try {
            $this->manager->seed('default');
        } finally {
            if ($previous === false) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV']);
            } else {
                putenv("APP_ENV={$previous}");
                $_ENV['APP_ENV'] = $previous;
            }
        }
    }

    /**
     * @return array{currencies: int, countries: int, provider_types: int, clients: int, admin_users: int}
     */
    private function snapshot(): array
    {
        return [
            'currencies' => $this->rowCount('currencies'),
            'countries' => $this->rowCount('countries'),
            'provider_types' => $this->rowCount('provider_types'),
            'clients' => $this->rowCount('clients'),
            'admin_users' => $this->rowCount('admin_users'),
        ];
    }

    private function rowCount(string $table): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM {$table}");
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
