<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use Gomrok\Shared\Infrastructure\Crypto\SodiumSecretCipher;
use PDO;
use Phinx\Seed\AbstractSeed;
use Throwable;

/**
 * Gives the `local-dev` client one test Stripe account + a webhook endpoint, so
 * `composer db:setup` and the Phase 10 routing tests have something to resolve.
 * Runs only for `APP_ENV` `local` / `testing` and only when `APP_ENCRYPTION_KEY`
 * is set. Idempotent. Fake credentials — never for production.
 */
final class ProviderAccountsSeeder extends AbstractSeed
{
    public const CLIENT_SLUG = 'local-dev';
    public const ACCOUNT_SLUG = 'stripe-test';
    public const WEBHOOK_TOKEN = 'whk_localdev_stripe_test';

    // Deliberately NOT shaped like a real provider credential (no `sk_`/`whsec_`
    // prefix) so GitHub secret scanning does not flag this fixture.
    private const FAKE_SECRET = 'gomrok-local-dev-fake-provider-secret';
    private const FAKE_WEBHOOK_SECRET = 'gomrok-local-dev-fake-webhook-signing-secret';

    /**
     * @return list<class-string<AbstractSeed>>
     */
    public function getDependencies(): array
    {
        return [ClientsSeeder::class, ProviderTypesSeeder::class];
    }

    public function run(): void
    {
        $env = getenv('APP_ENV');
        if (!\in_array($env, ['local', 'testing'], true)) {
            $this->output->writeln(\sprintf('<comment>ProviderAccountsSeeder skipped: APP_ENV is "%s".</comment>', $env === false ? '' : $env));

            return;
        }

        try {
            $key = getenv('APP_ENCRYPTION_KEY');
            $cipher = SodiumSecretCipher::fromBase64Key($key === false ? null : $key);
        } catch (Throwable $e) {
            $this->output->writeln('<comment>ProviderAccountsSeeder skipped: ' . $e->getMessage() . '</comment>');

            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $clientId = $this->id($pdo, 'SELECT id FROM clients WHERE slug = :v', ['v' => self::CLIENT_SLUG]);
        $providerTypeId = $this->id($pdo, 'SELECT id FROM provider_types WHERE code = :v', ['v' => 'stripe']);
        if ($clientId === null || $providerTypeId === null) {
            $this->output->writeln('<comment>ProviderAccountsSeeder skipped: local-dev client or stripe provider type missing.</comment>');

            return;
        }

        $pdo->prepare(
            "INSERT INTO provider_accounts
                (client_id, provider_type_id, slug, name, mode, status, public_key,
                 secret_ciphertext, secret_last_four, created_at, updated_at)
             VALUES (:client_id, :ptid, :slug, 'Local Dev Stripe (test)', 'test', 'active', 'gomrok-local-dev-fake-public-key',
                 :secret, :last_four, :now, :now)
             ON DUPLICATE KEY UPDATE secret_ciphertext = VALUES(secret_ciphertext), secret_last_four = VALUES(secret_last_four)",
        )->execute([
            'client_id' => $clientId,
            'ptid' => $providerTypeId,
            'slug' => self::ACCOUNT_SLUG,
            'secret' => $cipher->encrypt(self::FAKE_SECRET),
            'last_four' => substr(self::FAKE_SECRET, -4),
            'now' => $now,
        ]);

        $accountId = $this->id(
            $pdo,
            'SELECT id FROM provider_accounts WHERE client_id = :c AND slug = :v',
            ['c' => $clientId, 'v' => self::ACCOUNT_SLUG],
        );
        \assert($accountId !== null);

        foreach (['DE', 'NL'] as $country) {
            $pdo->prepare(
                'INSERT INTO provider_account_countries (provider_account_id, country_code, created_at)
                 VALUES (:id, :code, :now) ON DUPLICATE KEY UPDATE provider_account_id = provider_account_id',
            )->execute(['id' => $accountId, 'code' => $country, 'now' => $now]);
        }

        $pdo->prepare(
            "INSERT INTO provider_account_methods (provider_account_id, payment_method, created_at)
             VALUES (:id, 'card', :now) ON DUPLICATE KEY UPDATE provider_account_id = provider_account_id",
        )->execute(['id' => $accountId, 'now' => $now]);

        $pdo->prepare(
            "INSERT INTO provider_account_endpoints
                (provider_account_id, kind, token, signing_secret_ciphertext, is_active, created_at, updated_at)
             VALUES (:id, 'webhook', :token, :secret, 1, :now, :now)
             ON DUPLICATE KEY UPDATE signing_secret_ciphertext = VALUES(signing_secret_ciphertext), is_active = 1",
        )->execute([
            'id' => $accountId,
            'token' => self::WEBHOOK_TOKEN,
            'secret' => $cipher->encrypt(self::FAKE_WEBHOOK_SECRET),
            'now' => $now,
        ]);

        $this->output->writeln(\sprintf(
            '<info>ProviderAccountsSeeder: %s/%s (id %d) ready; webhook token %s</info>',
            self::CLIENT_SLUG,
            self::ACCOUNT_SLUG,
            $accountId,
            self::WEBHOOK_TOKEN,
        ));
    }

    /**
     * @param array<string, scalar> $params
     */
    private function id(PDO $pdo, string $sql, array $params): ?int
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
