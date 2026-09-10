<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use PDO;
use Phinx\Seed\AbstractSeed;

/**
 * Gives the `local-dev` client two demo packages so `composer db:setup` and the
 * Phase 11 catalogue tests have something to resolve:
 *
 *   starter  — no restrictions (available everywhere)
 *   pro      — restricted to DE + EUR
 *
 * Runs only for `APP_ENV` `local` / `testing`. Idempotent.
 */
final class PackagesSeeder extends AbstractSeed
{
    private const CLIENT_SLUG = 'local-dev';

    /**
     * @return list<class-string<AbstractSeed>>
     */
    public function getDependencies(): array
    {
        return [ClientsSeeder::class];
    }

    public function run(): void
    {
        $env = getenv('APP_ENV');
        if (!\in_array($env, ['local', 'testing'], true)) {
            $this->output->writeln(\sprintf('<comment>PackagesSeeder skipped: APP_ENV is "%s".</comment>', $env === false ? '' : $env));

            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $statement = $pdo->prepare('SELECT id FROM clients WHERE slug = :slug');
        $statement->execute(['slug' => self::CLIENT_SLUG]);
        $clientId = $statement->fetchColumn();
        if ($clientId === false) {
            $this->output->writeln('<comment>PackagesSeeder skipped: local-dev client missing.</comment>');

            return;
        }
        $clientId = (int) $clientId;

        $starter = $this->upsertPackage($pdo, $clientId, 'starter', 'Starter', 'Entry-level package.', $now);
        $pro = $this->upsertPackage($pdo, $clientId, 'pro', 'Pro', 'Full-feature package.', $now);

        $this->replaceChildren($pdo, 'package_countries', 'country_code', $starter, [], $now);
        $this->replaceChildren($pdo, 'package_currencies', 'currency_code', $starter, [], $now);
        $this->replaceChildren($pdo, 'package_countries', 'country_code', $pro, ['DE'], $now);
        $this->replaceChildren($pdo, 'package_currencies', 'currency_code', $pro, ['EUR'], $now);

        // Purchase capabilities (Phase 12): starter = one-time; pro = one-time + subscription (7-day trial, 1-month).
        $this->replaceCapabilities($pdo, $starter, [['one_time_payment', 0, null, 1]], $now);
        $this->replaceCapabilities($pdo, $pro, [
            ['one_time_payment', 0, null, 1],
            ['subscription', 1, 7, 1],
        ], $now);

        $this->output->writeln('<info>PackagesSeeder: local-dev packages ready (starter, pro).</info>');
    }

    /**
     * @param list<array{0: string, 1: int, 2: int|null, 3: int|null}> $capabilities [type, has_trial, trial_days, duration_months]
     */
    private function replaceCapabilities(PDO $pdo, int $packageId, array $capabilities, string $now): void
    {
        $pdo->prepare('DELETE FROM package_purchase_capabilities WHERE package_id = :id')->execute(['id' => $packageId]);
        $insert = $pdo->prepare(
            'INSERT INTO package_purchase_capabilities
                (package_id, purchase_type, has_trial, trial_days, duration_months, created_at, updated_at)
             VALUES (:id, :type, :has_trial, :trial_days, :duration_months, :now, :now)',
        );
        foreach ($capabilities as [$type, $hasTrial, $trialDays, $durationMonths]) {
            $insert->execute([
                'id' => $packageId,
                'type' => $type,
                'has_trial' => $hasTrial,
                'trial_days' => $trialDays,
                'duration_months' => $durationMonths,
                'now' => $now,
            ]);
        }
    }

    private function upsertPackage(PDO $pdo, int $clientId, string $code, string $name, string $description, string $now): int
    {
        $pdo->prepare(
            "INSERT INTO packages (client_id, code, name, description, status, metadata, created_at, updated_at)
             VALUES (:client_id, :code, :name, :description, 'active', NULL, :now, :now)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), updated_at = VALUES(updated_at)",
        )->execute([
            'client_id' => $clientId,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'now' => $now,
        ]);

        $statement = $pdo->prepare('SELECT id FROM packages WHERE client_id = :client_id AND code = :code');
        $statement->execute(['client_id' => $clientId, 'code' => $code]);
        $id = $statement->fetchColumn();
        \assert($id !== false);

        return (int) $id;
    }

    /**
     * @param list<string> $values
     */
    private function replaceChildren(PDO $pdo, string $table, string $column, int $packageId, array $values, string $now): void
    {
        $pdo->prepare("DELETE FROM {$table} WHERE package_id = :id")->execute(['id' => $packageId]);
        foreach ($values as $value) {
            $pdo->prepare(
                "INSERT INTO {$table} (package_id, {$column}, created_at) VALUES (:id, :value, :now)",
            )->execute(['id' => $packageId, 'value' => $value, 'now' => $now]);
        }
    }
}
