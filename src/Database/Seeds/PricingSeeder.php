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
 * Phase 15: every group gets a control price list; `dach` additionally gets a
 * disabled "List B · -10%" (factor 0.9000) with an exact `pro` price of €21.00.
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

        // Phase 15: a control list per group + a disabled -10% experiment on dach.
        $this->upsertControlList($pdo, $clientId, $default, $now);
        $this->upsertControlList($pdo, $clientId, $us, $now);
        $this->upsertControlList($pdo, $clientId, $dach, $now);
        $listB = $this->upsertExperimentList($pdo, $clientId, $dach, 'List B · -10%', '0.9000', false, $now);

        $starter = $this->scalarId($pdo, 'SELECT id FROM packages WHERE client_id = :c AND code = :v', ['c' => $clientId, 'v' => 'starter']);
        $pro = $this->scalarId($pdo, 'SELECT id FROM packages WHERE client_id = :c AND code = :v', ['c' => $clientId, 'v' => 'pro']);
        if ($starter !== null) {
            $this->upsertDefaultPrice($pdo, $starter, 900, 'EUR', $now);
        }
        if ($pro !== null) {
            $this->upsertDefaultPrice($pdo, $pro, 2900, 'EUR', $now);
            $this->upsertGroupPackage($pdo, $dach, $pro, 'override', 2400, 'EUR', $now);
            $this->upsertListPackagePrice($pdo, $listB, $pro, 2100, 'EUR', $now);

            // Phase 14 price rules: a Stripe card-fee discount, and yearly-in-US unavailable.
            $pdo->prepare('DELETE FROM price_rules WHERE client_id = :c AND package_id = :p')->execute(['c' => $clientId, 'p' => $pro]);
            $stripe = $this->scalarId($pdo, 'SELECT id FROM provider_accounts WHERE client_id = :c AND slug = :v', ['c' => $clientId, 'v' => 'stripe-test']);
            if ($stripe !== null) {
                $this->upsertPriceRule($pdo, $clientId, $pro, [
                    'provider_account_id' => $stripe,
                    'currency_code' => 'EUR',
                ], true, 2700, $now);
            }
            $this->upsertPriceRule($pdo, $clientId, $pro, [
                'pricing_group_id' => $us,
                'purchase_type' => 'subscription',
                'subscription_interval' => 'yearly',
            ], false, null, $now);
        }

        $pdo->prepare(
            'INSERT INTO client_exchange_rates (client_id, base_currency, quote_currency, rate, effective_from, created_at)
             VALUES (:c, :base, :quote, :rate, :from, :now)
             ON DUPLICATE KEY UPDATE rate = VALUES(rate)',
        )->execute(['c' => $clientId, 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.08000000', 'from' => '2026-01-01 00:00:00', 'now' => $now]);

        $this->output->writeln('<info>PricingSeeder: local-dev pricing ready (default, dach, us groups; control lists + dach List B; EUR->USD rate).</info>');
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

    private function upsertControlList(PDO $pdo, int $clientId, int $groupId, string $now): int
    {
        return $this->upsertExperimentList($pdo, $clientId, $groupId, 'List A · control', '1.0000', true, $now, true);
    }

    private function upsertExperimentList(PDO $pdo, int $clientId, int $groupId, string $name, string $factor, bool $enabled, string $now, bool $isControl = false): int
    {
        $pdo->prepare(
            'INSERT INTO price_lists (client_id, pricing_group_id, name, is_control, factor, is_enabled, created_at, updated_at)
             VALUES (:c, :g, :name, :control, :factor, :enabled, :now, :now)
             ON DUPLICATE KEY UPDATE factor = VALUES(factor), is_enabled = VALUES(is_enabled), updated_at = VALUES(updated_at)',
        )->execute([
            'c' => $clientId,
            'g' => $groupId,
            'name' => $name,
            'control' => $isControl ? 1 : 0,
            'factor' => $factor,
            'enabled' => $enabled ? 1 : 0,
            'now' => $now,
        ]);

        $id = $this->scalarId($pdo, 'SELECT id FROM price_lists WHERE pricing_group_id = :g AND name = :n', ['g' => $groupId, 'n' => $name]);
        \assert($id !== null);

        return $id;
    }

    private function upsertListPackagePrice(PDO $pdo, int $priceListId, int $packageId, int $amountMinor, string $currency, string $now): void
    {
        $pdo->prepare(
            'INSERT INTO price_list_packages (price_list_id, package_id, amount_minor, currency_code, created_at, updated_at)
             VALUES (:l, :p, :a, :c, :now, :now)
             ON DUPLICATE KEY UPDATE amount_minor = VALUES(amount_minor), currency_code = VALUES(currency_code), updated_at = VALUES(updated_at)',
        )->execute(['l' => $priceListId, 'p' => $packageId, 'a' => $amountMinor, 'c' => $currency, 'now' => $now]);
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
     * @param array<string, scalar> $dimensions
     */
    private function upsertPriceRule(PDO $pdo, int $clientId, int $packageId, array $dimensions, bool $isAvailable, ?int $amountMinor, string $now): void
    {
        $cols = ['pricing_group_id', 'country_code', 'provider_account_id', 'payment_method', 'purchase_type', 'subscription_interval', 'currency_code'];
        $params = ['c' => $clientId, 'p' => $packageId, 'avail' => $isAvailable ? 1 : 0, 'amount' => $amountMinor, 'now' => $now];
        $placeholders = [];
        foreach ($cols as $col) {
            $params[$col] = $dimensions[$col] ?? null;
            $placeholders[] = ":{$col}";
        }

        $pdo->prepare(
            'INSERT INTO price_rules
                (client_id, package_id, ' . implode(', ', $cols) . ', is_available, amount_minor, created_at, updated_at)
             VALUES (:c, :p, ' . implode(', ', $placeholders) . ', :avail, :amount, :now, :now)',
        )->execute($params);
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
