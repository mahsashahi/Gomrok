<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use Gomrok\Shared\Infrastructure\Crypto\SodiumSecretCipher;
use PDO;
use Phinx\Seed\AbstractSeed;
use Throwable;

/**
 * Wires the `local-dev` client with a full country → provider routing setup so
 * `composer db:setup` and the Phase 10 routing exit criteria have real data:
 *
 *   turkey       TR   one_time_payment                 → ziraat-test
 *   germany      DE   one_time_payment, subscription   → mollie-test  (card, paypal)
 *   netherlands  NL   one_time_payment                 → paypal-test
 *   default      —    one_time_payment, subscription   → stripe-test
 *
 * Runs only for `APP_ENV` `local` / `testing` and only when `APP_ENCRYPTION_KEY`
 * is set. Idempotent. Fake credentials — never for production.
 */
final class ProviderGroupsSeeder extends AbstractSeed
{
    private const CLIENT_SLUG = 'local-dev';

    /** @var array<string, array{type: string, countries: list<string>, methods: list<string>}> */
    private const ACCOUNTS = [
        'ziraat-test' => ['type' => 'ziraat', 'countries' => ['TR'], 'methods' => ['bank_hosted_card']],
        'mollie-test' => ['type' => 'mollie', 'countries' => ['DE'], 'methods' => ['card', 'paypal']],
        'paypal-test' => ['type' => 'paypal', 'countries' => ['NL'], 'methods' => ['paypal']],
    ];

    /**
     * @var list<array{
     *     slug: string, name: string, is_default: bool, countries: list<string>,
     *     purchase_types: list<string>, methods: list<string>, accounts: list<string>
     * }>
     */
    private const GROUPS = [
        ['slug' => 'turkey', 'name' => 'Turkey', 'is_default' => false, 'countries' => ['TR'],
            'purchase_types' => ['one_time_payment'], 'methods' => [], 'accounts' => ['ziraat-test']],
        ['slug' => 'germany', 'name' => 'Germany', 'is_default' => false, 'countries' => ['DE'],
            'purchase_types' => ['one_time_payment', 'subscription'], 'methods' => ['card', 'paypal'], 'accounts' => ['mollie-test']],
        ['slug' => 'netherlands', 'name' => 'Netherlands', 'is_default' => false, 'countries' => ['NL'],
            'purchase_types' => ['one_time_payment'], 'methods' => [], 'accounts' => ['paypal-test']],
        ['slug' => 'default', 'name' => 'Default', 'is_default' => true, 'countries' => [],
            'purchase_types' => ['one_time_payment', 'subscription'], 'methods' => [], 'accounts' => ['stripe-test']],
    ];

    /**
     * @return list<class-string<AbstractSeed>>
     */
    public function getDependencies(): array
    {
        return [ProviderAccountsSeeder::class, ProviderTypeDeclarationsSeeder::class];
    }

    public function run(): void
    {
        $env = getenv('APP_ENV');
        if (!\in_array($env, ['local', 'testing'], true)) {
            $this->output->writeln(\sprintf('<comment>ProviderGroupsSeeder skipped: APP_ENV is "%s".</comment>', $env === false ? '' : $env));

            return;
        }

        try {
            $key = getenv('APP_ENCRYPTION_KEY');
            $cipher = SodiumSecretCipher::fromBase64Key($key === false ? null : $key);
        } catch (Throwable $e) {
            $this->output->writeln('<comment>ProviderGroupsSeeder skipped: ' . $e->getMessage() . '</comment>');

            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $clientId = $this->scalarId($pdo, 'SELECT id FROM clients WHERE slug = :v', ['v' => self::CLIENT_SLUG]);
        if ($clientId === null) {
            $this->output->writeln('<comment>ProviderGroupsSeeder skipped: local-dev client missing.</comment>');

            return;
        }

        $accountIds = [];
        foreach (self::ACCOUNTS as $slug => $spec) {
            $accountIds[$slug] = $this->upsertAccount($pdo, $cipher, $clientId, $slug, $spec, $now);
        }
        $stripe = $this->scalarId(
            $pdo,
            'SELECT id FROM provider_accounts WHERE client_id = :c AND slug = :v',
            ['c' => $clientId, 'v' => 'stripe-test'],
        );
        if ($stripe !== null) {
            $accountIds['stripe-test'] = $stripe;
        }

        foreach (self::GROUPS as $group) {
            $this->upsertGroup($pdo, $clientId, $group, $accountIds, $now);
        }

        $this->output->writeln('<info>ProviderGroupsSeeder: local-dev routing groups ready (turkey, germany, netherlands, default).</info>');
    }

    /**
     * @param array{type: string, countries: list<string>, methods: list<string>} $spec
     */
    private function upsertAccount(PDO $pdo, SodiumSecretCipher $cipher, int $clientId, string $slug, array $spec, string $now): int
    {
        $providerTypeId = $this->scalarId($pdo, 'SELECT id FROM provider_types WHERE code = :v', ['v' => $spec['type']]);
        \assert($providerTypeId !== null);

        // Deliberately NOT shaped like a real provider credential — keeps GitHub
        // secret scanning from flagging this fixture.
        $secret = 'gomrok-local-dev-fake-secret-' . $slug;
        $pdo->prepare(
            "INSERT INTO provider_accounts
                (client_id, provider_type_id, slug, name, mode, status, public_key,
                 secret_ciphertext, secret_last_four, created_at, updated_at)
             VALUES (:client_id, :ptid, :slug, :name, 'test', 'active', :pk, :secret, :last_four, :now, :now)
             ON DUPLICATE KEY UPDATE secret_ciphertext = VALUES(secret_ciphertext), secret_last_four = VALUES(secret_last_four)",
        )->execute([
            'client_id' => $clientId,
            'ptid' => $providerTypeId,
            'slug' => $slug,
            'name' => 'Local Dev ' . ucfirst($spec['type']) . ' (test)',
            'pk' => 'gomrok-local-dev-fake-pk-' . $spec['type'],
            'secret' => $cipher->encrypt($secret),
            'last_four' => substr($secret, -4),
            'now' => $now,
        ]);

        $accountId = $this->scalarId(
            $pdo,
            'SELECT id FROM provider_accounts WHERE client_id = :c AND slug = :v',
            ['c' => $clientId, 'v' => $slug],
        );
        \assert($accountId !== null);

        foreach ($spec['countries'] as $country) {
            $pdo->prepare(
                'INSERT INTO provider_account_countries (provider_account_id, country_code, created_at)
                 VALUES (:id, :code, :now) ON DUPLICATE KEY UPDATE provider_account_id = provider_account_id',
            )->execute(['id' => $accountId, 'code' => $country, 'now' => $now]);
        }
        foreach ($spec['methods'] as $method) {
            $pdo->prepare(
                'INSERT INTO provider_account_methods (provider_account_id, payment_method, created_at)
                 VALUES (:id, :method, :now) ON DUPLICATE KEY UPDATE provider_account_id = provider_account_id',
            )->execute(['id' => $accountId, 'method' => $method, 'now' => $now]);
        }

        return $accountId;
    }

    /**
     * @param array{slug: string, name: string, is_default: bool, countries: list<string>, purchase_types: list<string>, methods: list<string>, accounts: list<string>} $group
     * @param array<string, int>                                                                                                                                       $accountIds
     */
    private function upsertGroup(PDO $pdo, int $clientId, array $group, array $accountIds, string $now): void
    {
        $pdo->prepare(
            "INSERT INTO provider_groups (client_id, slug, name, is_default, device_type, currency_code, status, created_at, updated_at)
             VALUES (:client_id, :slug, :name, :is_default, NULL, NULL, 'active', :now, :now)
             ON DUPLICATE KEY UPDATE name = VALUES(name), is_default = VALUES(is_default), updated_at = VALUES(updated_at)",
        )->execute([
            'client_id' => $clientId,
            'slug' => $group['slug'],
            'name' => $group['name'],
            'is_default' => $group['is_default'] ? 1 : 0,
            'now' => $now,
        ]);

        $groupId = $this->scalarId(
            $pdo,
            'SELECT id FROM provider_groups WHERE client_id = :c AND slug = :v',
            ['c' => $clientId, 'v' => $group['slug']],
        );
        \assert($groupId !== null);

        $this->replaceChildren($pdo, 'provider_group_countries', 'country_code', $groupId, $group['countries'], $now);
        $this->replaceChildren($pdo, 'provider_group_purchase_types', 'purchase_type', $groupId, $group['purchase_types'], $now);
        $this->replaceChildren($pdo, 'provider_group_methods', 'payment_method', $groupId, $group['methods'], $now);

        $pdo->prepare('DELETE FROM provider_group_accounts WHERE provider_group_id = :id')->execute(['id' => $groupId]);
        $priority = 0;
        foreach ($group['accounts'] as $slug) {
            if (!isset($accountIds[$slug])) {
                continue;
            }
            $pdo->prepare(
                'INSERT INTO provider_group_accounts (provider_group_id, provider_account_id, priority, is_enabled, created_at)
                 VALUES (:gid, :aid, :priority, 1, :now)',
            )->execute(['gid' => $groupId, 'aid' => $accountIds[$slug], 'priority' => $priority, 'now' => $now]);
            ++$priority;
        }
    }

    /**
     * @param list<string> $values
     */
    private function replaceChildren(PDO $pdo, string $table, string $column, int $groupId, array $values, string $now): void
    {
        $pdo->prepare("DELETE FROM {$table} WHERE provider_group_id = :id")->execute(['id' => $groupId]);
        foreach ($values as $value) {
            $pdo->prepare(
                "INSERT INTO {$table} (provider_group_id, {$column}, created_at) VALUES (:id, :value, :now)",
            )->execute(['id' => $groupId, 'value' => $value, 'now' => $now]);
        }
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
