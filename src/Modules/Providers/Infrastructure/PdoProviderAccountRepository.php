<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\EncryptedSecret;
use Gomrok\Modules\Providers\Domain\EndpointKind;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccount;
use Gomrok\Modules\Providers\Domain\ProviderAccountEndpoint;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Modules\Providers\Domain\ProviderAccountSlug;
use Gomrok\Modules\Providers\Domain\ProviderAccountStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see ProviderAccountRepository}. `save()` writes the `provider_accounts`
 * row then synchronises `provider_account_countries` / `provider_account_methods`
 * (delete + reinsert) and `provider_account_endpoints` (insert new, update
 * existing — endpoints are never deleted, only deactivated). Callers wrap it in
 * a transaction.
 */
final readonly class PdoProviderAccountRepository implements ProviderAccountRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ProviderAccount $account): void
    {
        if ($account->id() === null) {
            $this->insert($account);
        } else {
            $this->update($account);
        }

        $id = $account->id();
        \assert($id !== null);

        $this->syncCountries($id, $account->countryCodes());
        $this->syncMethods($id, $account->methods());
        $this->syncEndpoints($id, $account->endpoints());
    }

    public function findById(int $id): ?ProviderAccount
    {
        return $this->hydrate($this->fetchOne('SELECT * FROM provider_accounts WHERE id = :v', $id));
    }

    public function findByClientAndSlug(int $clientId, string $slug): ?ProviderAccount
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_accounts WHERE client_id = :client_id AND slug = :slug');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);

        return $this->hydrate($statement->fetch());
    }

    public function existsForClientWithSlug(int $clientId, string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM provider_accounts WHERE client_id = :client_id AND slug = :slug LIMIT 1');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);

        return $statement->fetchColumn() !== false;
    }

    public function findByEndpointToken(string $token): ?ProviderAccount
    {
        $statement = $this->pdo->prepare(
            'SELECT pa.* FROM provider_account_endpoints e
               JOIN provider_accounts pa ON pa.id = e.provider_account_id
              WHERE e.token = :token AND e.is_active = 1',
        );
        $statement->execute(['token' => $token]);

        return $this->hydrate($statement->fetch());
    }

    private function insert(ProviderAccount $account): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO provider_accounts
                (client_id, provider_type_id, slug, name, mode, status, public_key,
                 secret_ciphertext, secret_last_four, created_at, updated_at)
             VALUES
                (:client_id, :provider_type_id, :slug, :name, :mode, :status, :public_key,
                 :secret_ciphertext, :secret_last_four, :created_at, :updated_at)',
        );
        $statement->execute([
            'client_id' => $account->clientId(),
            'provider_type_id' => $account->providerTypeId(),
            'slug' => (string) $account->slug(),
            'name' => $account->name(),
            'mode' => $account->mode()->value,
            'status' => $account->status()->value,
            'public_key' => $account->publicKey(),
            'secret_ciphertext' => $account->secret()->ciphertext,
            'secret_last_four' => $account->secret()->lastFour,
            'created_at' => $account->createdAt()->format(self::DT),
            'updated_at' => $account->updatedAt()?->format(self::DT),
        ]);

        $account->assignId((int) $this->pdo->lastInsertId());
    }

    private function update(ProviderAccount $account): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE provider_accounts SET
                name = :name, status = :status, public_key = :public_key,
                secret_ciphertext = :secret_ciphertext, secret_last_four = :secret_last_four,
                disabled_at = :disabled_at, disabled_by = :disabled_by, disabled_reason = :disabled_reason,
                updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $account->id(),
            'name' => $account->name(),
            'status' => $account->status()->value,
            'public_key' => $account->publicKey(),
            'secret_ciphertext' => $account->secret()->ciphertext,
            'secret_last_four' => $account->secret()->lastFour,
            'disabled_at' => $account->disabledAt()?->format(self::DT),
            'disabled_by' => $account->disabledBy(),
            'disabled_reason' => $account->disabledReason(),
            'updated_at' => $account->updatedAt()?->format(self::DT),
        ]);
    }

    /**
     * @param list<string> $countryCodes
     */
    private function syncCountries(int $accountId, array $countryCodes): void
    {
        $this->pdo->prepare('DELETE FROM provider_account_countries WHERE provider_account_id = :id')
            ->execute(['id' => $accountId]);

        if ($countryCodes === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO provider_account_countries (provider_account_id, country_code, created_at) VALUES (:id, :code, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($countryCodes as $code) {
            $insert->execute(['id' => $accountId, 'code' => $code, 'now' => $now]);
        }
    }

    /**
     * @param list<PaymentMethod> $methods
     */
    private function syncMethods(int $accountId, array $methods): void
    {
        $this->pdo->prepare('DELETE FROM provider_account_methods WHERE provider_account_id = :id')
            ->execute(['id' => $accountId]);

        if ($methods === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO provider_account_methods (provider_account_id, payment_method, created_at) VALUES (:id, :method, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($methods as $method) {
            $insert->execute(['id' => $accountId, 'method' => $method->value, 'now' => $now]);
        }
    }

    /**
     * @param list<ProviderAccountEndpoint> $endpoints
     */
    private function syncEndpoints(int $accountId, array $endpoints): void
    {
        $now = gmdate(self::DT);
        $insert = $this->pdo->prepare(
            'INSERT INTO provider_account_endpoints
                (provider_account_id, kind, token, signing_secret_ciphertext, is_active, created_at, updated_at)
             VALUES (:id, :kind, :token, :secret, :active, :now, :now)',
        );
        $update = $this->pdo->prepare(
            'UPDATE provider_account_endpoints
                SET signing_secret_ciphertext = :secret, is_active = :active, updated_at = :now
              WHERE id = :id',
        );

        foreach ($endpoints as $endpoint) {
            if ($endpoint->id() === null) {
                $insert->execute([
                    'id' => $accountId,
                    'kind' => $endpoint->kind()->value,
                    'token' => $endpoint->token(),
                    'secret' => $endpoint->signingSecretCiphertext(),
                    'active' => $endpoint->isActive() ? 1 : 0,
                    'now' => $now,
                ]);
                $endpoint->assignId((int) $this->pdo->lastInsertId());
            } else {
                $update->execute([
                    'id' => $endpoint->id(),
                    'secret' => $endpoint->signingSecretCiphertext(),
                    'active' => $endpoint->isActive() ? 1 : 0,
                    'now' => $now,
                ]);
            }
        }
    }

    /**
     * @return array<array-key, mixed>|false
     */
    private function fetchOne(string $sql, int $value): array|false
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['v' => $value]);
        $row = $statement->fetch();

        return \is_array($row) ? $row : false;
    }

    private function hydrate(mixed $row): ?ProviderAccount
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = Row::int($row['id'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);
        $disabledAt = Row::nullableStr($row['disabled_at'] ?? null);

        return ProviderAccount::fromStorage(
            $id,
            Row::int($row['client_id'] ?? null),
            Row::int($row['provider_type_id'] ?? null),
            ProviderAccountSlug::of(Row::str($row['slug'] ?? '')),
            Row::str($row['name'] ?? ''),
            ProviderAccountMode::from(Row::str($row['mode'] ?? 'test')),
            ProviderAccountStatus::from(Row::str($row['status'] ?? 'active')),
            Row::nullableStr($row['public_key'] ?? null),
            new EncryptedSecret(Row::str($row['secret_ciphertext'] ?? ''), Row::str($row['secret_last_four'] ?? '')),
            $this->countriesOf($id),
            $this->methodsOf($id),
            $this->endpointsOf($id),
            $disabledAt !== null ? new DateTimeImmutable($disabledAt) : null,
            Row::nullableInt($row['disabled_by'] ?? null),
            Row::nullableStr($row['disabled_reason'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }

    /**
     * @return list<string>
     */
    private function countriesOf(int $accountId): array
    {
        $statement = $this->pdo->prepare('SELECT country_code FROM provider_account_countries WHERE provider_account_id = :id ORDER BY country_code');
        $statement->execute(['id' => $accountId]);

        $codes = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $codes[] = Row::str($value);
        }

        return $codes;
    }

    /**
     * @return list<PaymentMethod>
     */
    private function methodsOf(int $accountId): array
    {
        $statement = $this->pdo->prepare('SELECT payment_method FROM provider_account_methods WHERE provider_account_id = :id ORDER BY payment_method');
        $statement->execute(['id' => $accountId]);

        $methods = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $method = PaymentMethod::tryFrom(Row::str($value));
            if ($method !== null) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * @return list<ProviderAccountEndpoint>
     */
    private function endpointsOf(int $accountId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, kind, token, signing_secret_ciphertext, is_active
               FROM provider_account_endpoints WHERE provider_account_id = :id ORDER BY id',
        );
        $statement->execute(['id' => $accountId]);

        $endpoints = [];
        while (($row = $statement->fetch()) !== false) {
            if (!\is_array($row)) {
                continue;
            }
            $kind = EndpointKind::tryFrom(Row::str($row['kind'] ?? ''));
            if ($kind === null) {
                continue;
            }
            $endpoints[] = ProviderAccountEndpoint::fromStorage(
                Row::int($row['id'] ?? null),
                $kind,
                Row::nullableStr($row['token'] ?? null),
                Row::nullableStr($row['signing_secret_ciphertext'] ?? null),
                Row::bool($row['is_active'] ?? null),
            );
        }

        return $endpoints;
    }
}
