<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use PDO;
use Phinx\Seed\AbstractSeed;

/**
 * Wires the `local-dev` client with two demo vouchers (Phase 16):
 *
 *   WELCOME10 — 10% off (default_percent_bp=1000), max_per_user=1, otherwise
 *               unlimited. The canonical "valid for everyone, once per user" case.
 *   EU5       — default_discount_type=none, with per-currency fixed overrides
 *               (EUR 5.00, USD 6.00, GBP 4.00) and an eligibility rule
 *               restricting it to the `pro` package.
 *
 * Runs only for `APP_ENV` `local` / `testing`. Idempotent.
 */
final class VouchersSeeder extends AbstractSeed
{
    private const CLIENT_SLUG = 'local-dev';

    /**
     * @return list<class-string<AbstractSeed>>
     */
    public function getDependencies(): array
    {
        return [PricingSeeder::class];
    }

    public function run(): void
    {
        $env = getenv('APP_ENV');
        if (!\in_array($env, ['local', 'testing'], true)) {
            $this->output->writeln(\sprintf('<comment>VouchersSeeder skipped: APP_ENV is "%s".</comment>', $env === false ? '' : $env));

            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $clientId = $this->scalarId($pdo, 'SELECT id FROM clients WHERE slug = :v', ['v' => self::CLIENT_SLUG]);
        if ($clientId === null) {
            $this->output->writeln('<comment>VouchersSeeder skipped: local-dev client missing.</comment>');

            return;
        }

        $welcome = $this->upsertVoucher($pdo, $clientId, 'WELCOME10', 'Welcome 10% Off', 'percentage', 1000, null, 1, null, $now);
        $eu5 = $this->upsertVoucher($pdo, $clientId, 'EU5', 'Flat 5 Off', 'none', null, null, null, null, $now);

        $this->upsertCurrencyDiscount($pdo, $eu5, 'EUR', 'fixed', null, 500, null, $now);
        $this->upsertCurrencyDiscount($pdo, $eu5, 'USD', 'fixed', null, 600, null, $now);
        $this->upsertCurrencyDiscount($pdo, $eu5, 'GBP', 'fixed', null, 400, null, $now);

        $pro = $this->scalarId($pdo, 'SELECT id FROM packages WHERE client_id = :c AND code = :v', ['c' => $clientId, 'v' => 'pro']);
        if ($pro !== null) {
            $pdo->prepare('DELETE FROM voucher_eligibility_rules WHERE voucher_id = :id')->execute(['id' => $eu5]);
            $pdo->prepare('INSERT INTO voucher_eligibility_rules (voucher_id, dimension, value, created_at) VALUES (:v, :d, :val, :now)')
                ->execute(['v' => $eu5, 'd' => 'package', 'val' => (string) $pro, 'now' => $now]);
        }

        $this->output->writeln('<info>VouchersSeeder: local-dev vouchers ready (WELCOME10, EU5).</info>');
    }

    private function upsertVoucher(
        PDO $pdo,
        int $clientId,
        string $code,
        string $name,
        string $defaultDiscountType,
        ?int $defaultPercentBp,
        ?int $maxTotalRedemptions,
        ?int $maxPerUser,
        ?int $maxPerClient,
        string $now,
    ): int {
        $pdo->prepare(
            "INSERT INTO vouchers
                (client_id, code, name, status, default_discount_type, default_percent_bp,
                 max_total_redemptions, max_per_user, max_per_client, redeemed_count, created_at, updated_at)
             VALUES (:c, :code, :name, 'active', :type, :percent, :max_total, :max_user, :max_client, 0, :now, :now)
             ON DUPLICATE KEY UPDATE name = VALUES(name), default_discount_type = VALUES(default_discount_type),
                default_percent_bp = VALUES(default_percent_bp), max_total_redemptions = VALUES(max_total_redemptions),
                max_per_user = VALUES(max_per_user), max_per_client = VALUES(max_per_client), updated_at = VALUES(updated_at)",
        )->execute([
            'c' => $clientId,
            'code' => $code,
            'name' => $name,
            'type' => $defaultDiscountType,
            'percent' => $defaultPercentBp,
            'max_total' => $maxTotalRedemptions,
            'max_user' => $maxPerUser,
            'max_client' => $maxPerClient,
            'now' => $now,
        ]);

        $id = $this->scalarId($pdo, 'SELECT id FROM vouchers WHERE client_id = :c AND code = :v', ['c' => $clientId, 'v' => $code]);
        \assert($id !== null);

        return $id;
    }

    private function upsertCurrencyDiscount(PDO $pdo, int $voucherId, string $currency, string $type, ?int $percentBp, ?int $amountMinor, ?int $maxDiscountMinor, string $now): void
    {
        $pdo->prepare(
            'INSERT INTO voucher_currency_discounts (voucher_id, currency_code, discount_type, percent_bp, amount_minor, max_discount_minor, created_at, updated_at)
             VALUES (:v, :c, :type, :percent, :amount, :max_discount, :now, :now)
             ON DUPLICATE KEY UPDATE discount_type = VALUES(discount_type), percent_bp = VALUES(percent_bp),
                amount_minor = VALUES(amount_minor), max_discount_minor = VALUES(max_discount_minor), updated_at = VALUES(updated_at)',
        )->execute(['v' => $voucherId, 'c' => $currency, 'type' => $type, 'percent' => $percentBp, 'amount' => $amountMinor, 'max_discount' => $maxDiscountMinor, 'now' => $now]);
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
