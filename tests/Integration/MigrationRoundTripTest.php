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
 * Drives every migration down to an empty schema and back up again, then
 * re-seeds so the rest of the integration suite still finds its reference data.
 * Skips when MySQL is unreachable.
 */
final class MigrationRoundTripTest extends TestCase
{
    /** Business tables the migrations own, in dependency order. */
    private const TABLES = [
        'currencies',
        'countries',
        'provider_types',
        'idempotency_keys',
        'audit_logs',
        'error_logs',
        'clients',
        'client_api_keys',
        'client_endpoints',
        'client_auth_attempts',
        'provider_capabilities',
        'provider_type_capabilities',
        'provider_type_purchase_types',
        'provider_accounts',
        'provider_account_endpoints',
        'provider_account_countries',
        'provider_account_methods',
        'provider_groups',
        'provider_group_countries',
        'provider_group_accounts',
        'provider_group_purchase_types',
        'provider_group_methods',
        'packages',
        'package_countries',
        'package_currencies',
        'package_payment_methods',
        'package_provider_accounts',
        'package_purchase_capabilities',
        'package_country_purchase_capabilities',
        'package_provider_definitions',
        'pricing_groups',
        'pricing_group_countries',
        'default_package_prices',
        'client_exchange_rates',
        'pricing_group_packages',
        'price_rules',
        'price_lists',
        'price_list_packages',
        'vouchers',
        'voucher_eligibility_rules',
        'voucher_currency_discounts',
        'voucher_redemptions',
        'checkout_attempts',
        'pricing_decision_snapshots',
        'voucher_decision_snapshots',
        'provider_routing_decision_snapshots',
    ];

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
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL not reachable (' . $e->getMessage() . ').');
        }

        /** @var array<string, mixed> $settings */
        $settings = require \dirname(__DIR__, 2) . '/phinx.php';
        $this->manager = new Manager(new Config($settings), new StringInput(' '), new NullOutput());
    }

    protected function tearDown(): void
    {
        if (!isset($this->manager)) {
            return;
        }

        // Always leave the database fully migrated and seeded for the other tests.
        $this->manager->migrate('default');
        $this->manager->seed('default');
    }

    #[Test]
    public function everyMigrationRollsDownAndBackUpCleanly(): void
    {
        $this->manager->rollback('default', 0);

        foreach (self::TABLES as $table) {
            self::assertFalse($this->tableExists($table), "{$table} should be gone after rollback");
        }

        $this->manager->migrate('default');

        foreach (self::TABLES as $table) {
            self::assertTrue($this->tableExists($table), "{$table} should exist after migrate");
        }
    }

    #[Test]
    public function crossCuttingTablesHaveTheExpectedColumns(): void
    {
        $this->manager->migrate('default');

        self::assertSame(
            ['id', 'client_id', 'idempotency_key', 'request_fingerprint', 'status', 'target_type', 'target_id', 'response_status', 'created_at', 'updated_at', 'expires_at'],
            $this->columns('idempotency_keys'),
        );
        self::assertSame(
            ['id', 'actor_type', 'actor_id', 'client_id', 'action', 'target_type', 'target_id', 'before', 'after', 'context', 'correlation_id', 'ip', 'user_agent', 'created_at'],
            $this->columns('audit_logs'),
        );
        self::assertSame(
            ['id', 'level', 'source', 'message', 'exception_class', 'code', 'client_id', 'correlation_id', 'context', 'stack_trace', 'created_at', 'resolved_at', 'resolved_by'],
            $this->columns('error_logs'),
        );
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = :t',
        );
        $statement->execute(['t' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT column_name FROM information_schema.columns
              WHERE table_schema = DATABASE() AND table_name = :t
              ORDER BY ordinal_position',
        );
        $statement->execute(['t' => $table]);

        /** @var list<string> $names */
        $names = $statement->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}
