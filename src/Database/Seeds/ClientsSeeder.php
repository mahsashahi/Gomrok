<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use Phinx\Seed\AbstractSeed;

/**
 * Seeds a single `local-dev` client with a **fixed** API key for local work and
 * the integration suite. Runs only when `APP_ENV` is `local` or `testing` — a
 * no-op otherwise, so it can never create a known-secret key in production.
 *
 * Idempotent (upsert on `clients.slug` / `client_api_keys.key_id`).
 */
final class ClientsSeeder extends AbstractSeed
{
    public const SLUG = 'local-dev';

    /** Fixed dev token — documented, safe only because the seeder is env-gated. */
    public const KEY_ID = '000000000000dead';
    public const SECRET = 'localdevsecretlocaldevsecret1234';
    public const TOKEN = 'gk_test_' . self::KEY_ID . '.' . self::SECRET;

    private const SIGNING_SECRET = 'localdevsigningsecret00000000000000000000';

    public function run(): void
    {
        $env = getenv('APP_ENV');
        if (!\in_array($env, ['local', 'testing'], true)) {
            $this->output->writeln(\sprintf(
                '<comment>ClientsSeeder skipped: APP_ENV is "%s" (only runs for local/testing).</comment>',
                $env === false ? '' : $env,
            ));

            return;
        }

        $pdo = $this->getAdapter()->getConnection();
        $now = gmdate('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO clients
                (slug, name, status, default_currency, default_country, timezone,
                 notification_signing_secret, created_at, updated_at)
             VALUES (:slug, :name, \'active\', \'EUR\', NULL, \'UTC\', :secret, :now, :now)
             ON DUPLICATE KEY UPDATE name = VALUES(name)',
        )->execute(['slug' => self::SLUG, 'name' => 'Local Dev', 'secret' => self::SIGNING_SECRET, 'now' => $now]);

        $clientIdStatement = $pdo->prepare('SELECT id FROM clients WHERE slug = :slug');
        $clientIdStatement->execute(['slug' => self::SLUG]);
        $clientId = (int) $clientIdStatement->fetchColumn();

        $pdo->prepare(
            'INSERT INTO client_api_keys
                (client_id, key_id, secret_hash, prefix, last_four, label, status, created_at)
             VALUES (:client_id, :key_id, :secret_hash, \'gk_test\', :last_four, \'local-dev\', \'active\', :now)
             ON DUPLICATE KEY UPDATE secret_hash = VALUES(secret_hash), status = \'active\'',
        )->execute([
            'client_id' => $clientId,
            'key_id' => self::KEY_ID,
            'secret_hash' => hash('sha256', self::SECRET),
            'last_four' => substr(self::SECRET, -4),
            'now' => $now,
        ]);

        $this->output->writeln(\sprintf(
            '<info>ClientsSeeder: client "%s" (id %d) ready; dev token %s</info>',
            self::SLUG,
            $clientId,
            self::TOKEN,
        ));
    }
}
