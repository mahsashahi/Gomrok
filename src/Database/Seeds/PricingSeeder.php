<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use PDO;
use Phinx\Seed\AbstractSeed;

/**
 * Wires the `local-dev` client with pricing so `composer db:setup` and the
 * Phase 13 tests / endpoints have data:
 *
 *   default  —   —          EUR   (fallback)
 *   dach     DE/AT/CH       EUR   priority 1   (pro overridden to €24.00)
 *   us       US             USD   priority 2   (baseline converted via EUR->USD)
 *
 *   default prices: starter €9.00, pro €29.00
 *   rate: EUR -> USD = 1.08
 *
 * Runs only for `APP_ENV` `local` / `testing`. Idempotent.
 */
final class PricingSeeder extends AbstractSeed
{
    private const CLIENT_SLUG = 'local-dev';

    /**
     * @return list<class-string<AbstractSeed>>
     */
    public function getDependencies(): array
    {
        return [PackagesSeeder::class];
    }

    public function run(): void
    {
        $env = getenv('APP_ENV');
        if (!\in_array($env, ['local', 'testing'], true)) {
            $this->output->writeln(\sprintf('<comment>PricingSeeder skipped: APP_ENV is "%s".</comment>', $env === false ? '' : $env));

            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $clientId = $this->scalarId($pdo, 'SELECT id FROM clients WHERE slug = :v', ['v' => self::CLIENT_SLUG]);
        if ($clientId === null) {
            $this->output->writeln('<comment>PricingSeeder skipped: local-dev client missing.</comment>');

            return;
        }

        $default = $this->upsertGroup($pdo, $clientId, 'default', 'Default', 0, null, 'EUR', true, $now);
        $dach = $this->upsertGroup($pdo, $clientId, 'dach', 'DACH', 1, null, 'EUR', false, $now);
        $us = $this->upsertGroup($pdo, $clientId, 'us', 'United States', 2, null, 'USD', false, $now);

        $this->replaceCountries($pdo, $default, [], $now);
        $this->replaceCountries($pdo, $dach, ['DE', 'AT', 'CH'], $now);
        $this->replaceCountries($pdo, $us, ['US'], $now);

        $starter = $this->scalarId($pdo, 'SELECT id FROM packages WHERE client_id = :c AND code = :v', ['c' => $clientId, 'v' => 'starter']);
        $pro = $this->scalarId($pdo, 'SELECT id FROM packages WHERE client_id = :c AND code = :v', ['c' => $clientId, 'v' => 'pro']);
        if ($starter !== null) {
            $this->upsertDefaultPrice($pdo, $starter, 900, 'EUR', $now);
        }
        if ($pro !== null) {
            $this->upsertDefaultPrice($pdo, $pro, 2900, 'EUR', $now);
            $this->upsertGroupPackage($pdo, $dach, $pro, 'override', 2400, 'EUR', $now);
        }

        $pdo->prepare(
            'INSERT INTO client_exchange_rates (client_id, base_currency, quote_currency, rate, effective_from, created_at)
             VALUES (:c, :base, :quote, :rate, :from, :now)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)',
        )->execute(['c' => $clientId, 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.08000000', 'from' => '2026-01-01 00:00:00', 'now' => $now]);

        $this->output->writeln('<info>PricingSeeder: local-dev pricing ready (default, dach, us groups; EUR->USD rate).</info>');
    }

    private function upsertGroup(PDO $pdo, int $clientId, string $slug, string $name, int $priority, ?string $deviceType, string $currency, bool $isDefault, string $now): int
    {
        $pdo->prepare(
            "INSERT INTO pricing_groups
                (client_id, slug, name, priority, device_type, currency_code, is_default, status, created_at, updated_at)
             VALUES (:c, :slug, :name, :priority, :device, :currency, :is_default, 'active', :now, :now)
             ON DUPLICATE KEY UPDATE name = VALUES(name), priority = VALUES(priority), updated_at = VALUES(updated_at)",
        )->execute([
            'c' => $clientId,
            'slug' => $slug,
            'name' => $name,
            'priority' => $priority,
            'device' => $deviceType,
            'currency' => $currency,
            'is_default' => $isDefault ? 1 : 0,
            'now' => $now,
        ]);

        $id = $this->scalarId($pdo, 'SELECT id FROM pricing_groups WHERE client_id = :c AND slug = :v', ['c' => $clientId, 'v' => $slug]);
        \assert($id !== null);

        return $id;
    }

    /**
     * @param list<string> $countries
     */
    private function replaceCountries(PDO $pdo, int $groupId, array $countries, string $now): void
    {
        $pdo->prepare('DELETE FROM pricing_group_countries WHERE pricing_group_id = :id')->execute(['id' => $groupId]);
        foreach ($countries as $country) {
            $pdo->prepare('INSERT INTO pricing_group_countries (pricing_group_id, country_code, created_at) VALUES (:id, :code, :now)')
                ->execute(['id' => $groupId, 'code' => $country, 'now' => $now]);
        }
    }

    private function upsertDefaultPrice(PDO $pdo, int $packageId, int $amountMinor, string $currency, string $now): void
    {
        $pdo->prepare(
            'INSERT INTO default_package_prices (package_id, amount_minor, currency_code, created_at, updated_at)
             VALUES (:p, :a, :c, :now, :now)
             ON DUPLICATE KEY UPDATE amount_minor = VALUES(amount_minor), currency_code = VALUES(currency_code), updated_at = VALUES(updated_at)',
        )->execute(['p' => $packageId, 'a' => $amountMinor, 'c' => $currency, 'now' => $now]);
    }

    private function upsertGroupPackage(PDO $pdo, int $groupId, int $packageId, string $status, int $amountMinor, string $currency, string $now): void
    {
        $pdo->prepare(
            'INSERT INTO pricing_group_packages
                (pricing_group_id, package_id, status, amount_minor, currency_code, display_order, created_at, updated_at)
             VALUES (:g, :p, :status, :a, :c, 0, :now, :now)
             ON DUPLICATE KEY UPDATE status = VALUES(status), amount_minor = VALUES(amount_minor), currency_code = VALUES(currency_code), updated_at = VALUES(updated_at)',
        )->execute(['g' => $groupId, 'p' => $packageId, 'status' => $status, 'a' => $amountMinor, 'c' => $currency, 'now' => $now]);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function scalarId(PDO $pdo, string $sql, array $params): ?int
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
