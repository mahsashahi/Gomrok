<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Domain\DeviceType;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Modules\Providers\Domain\ProviderGroupStatus;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see ProviderGroupRepository}. `save()` writes the `provider_groups`
 * row then rebuilds its child rows (`provider_group_countries` /
 * `_accounts` / `_purchase_types` / `_methods`) delete-and-reinsert. Callers
 * wrap it in a transaction.
 */
final readonly class PdoProviderGroupRepository implements ProviderGroupRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ProviderGroup $group): void
    {
        if ($group->id() === null) {
            $this->insert($group);
        } else {
            $this->update($group);
        }

        $id = $group->id();
        \assert($id !== null);

        $this->syncCountries($id, $group->countryCodes());
        $this->syncAccounts($id, $group->accounts());
        $this->syncPurchaseTypes($id, $group->purchaseTypes());
        $this->syncMethods($id, $group->methods());
    }

    public function findById(int $id): ?ProviderGroup
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_groups WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByClientAndSlug(int $clientId, string $slug): ?ProviderGroup
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_groups WHERE client_id = :client_id AND slug = :slug');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);

        return $this->hydrate($statement->fetch());
    }

    public function existsForClientWithSlug(int $clientId, string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM provider_groups WHERE client_id = :client_id AND slug = :slug LIMIT 1');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);

        return $statement->fetchColumn() !== false;
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_groups WHERE client_id = :client_id ORDER BY is_default ASC, slug ASC');
        $statement->execute(['client_id' => $clientId]);

        $groups = [];
        while (($row = $statement->fetch()) !== false) {
            $group = $this->hydrate($row);
            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    private function insert(ProviderGroup $group): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO provider_groups
                (client_id, slug, name, is_default, device_type, currency_code, status, created_at, updated_at)
             VALUES
                (:client_id, :slug, :name, :is_default, :device_type, :currency_code, :status, :created_at, :updated_at)',
        );
        $statement->execute([
            'client_id' => $group->clientId(),
            'slug' => $group->slug()->value,
            'name' => $group->name(),
            'is_default' => $group->isDefault() ? 1 : 0,
            'device_type' => $group->deviceType()?->value,
            'currency_code' => $group->currencyCode(),
            'status' => $group->status()->value,
            'created_at' => $group->createdAt()->format(self::DT),
            'updated_at' => $group->updatedAt()?->format(self::DT),
        ]);

        $group->assignId((int) $this->pdo->lastInsertId());
    }

    private function update(ProviderGroup $group): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE provider_groups SET
                name = :name, currency_code = :currency_code, status = :status, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $group->id(),
            'name' => $group->name(),
            'currency_code' => $group->currencyCode(),
            'status' => $group->status()->value,
            'updated_at' => $group->updatedAt()?->format(self::DT),
        ]);
    }

    /**
     * @param list<string> $countryCodes
     */
    private function syncCountries(int $groupId, array $countryCodes): void
    {
        $this->pdo->prepare('DELETE FROM provider_group_countries WHERE provider_group_id = :id')->execute(['id' => $groupId]);
        if ($countryCodes === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO provider_group_countries (provider_group_id, country_code, created_at) VALUES (:id, :code, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($countryCodes as $code) {
            $insert->execute(['id' => $groupId, 'code' => $code, 'now' => $now]);
        }
    }

    /**
     * @param list<ProviderGroupAccount> $accounts
     */
    private function syncAccounts(int $groupId, array $accounts): void
    {
        $this->pdo->prepare('DELETE FROM provider_group_accounts WHERE provider_group_id = :id')->execute(['id' => $groupId]);
        if ($accounts === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO provider_group_accounts (provider_group_id, provider_account_id, priority, is_enabled, created_at)
             VALUES (:id, :account_id, :priority, :is_enabled, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($accounts as $account) {
            $insert->execute([
                'id' => $groupId,
                'account_id' => $account->providerAccountId(),
                'priority' => $account->priority(),
                'is_enabled' => $account->isEnabled() ? 1 : 0,
                'now' => $now,
            ]);
            $account->assignId((int) $this->pdo->lastInsertId());
        }
    }

    /**
     * @param list<PurchaseType> $purchaseTypes
     */
    private function syncPurchaseTypes(int $groupId, array $purchaseTypes): void
    {
        $this->pdo->prepare('DELETE FROM provider_group_purchase_types WHERE provider_group_id = :id')->execute(['id' => $groupId]);
        if ($purchaseTypes === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO provider_group_purchase_types (provider_group_id, purchase_type, created_at) VALUES (:id, :value, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($purchaseTypes as $purchaseType) {
            $insert->execute(['id' => $groupId, 'value' => $purchaseType->value, 'now' => $now]);
        }
    }

    /**
     * @param list<PaymentMethod> $methods
     */
    private function syncMethods(int $groupId, array $methods): void
    {
        $this->pdo->prepare('DELETE FROM provider_group_methods WHERE provider_group_id = :id')->execute(['id' => $groupId]);
        if ($methods === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO provider_group_methods (provider_group_id, payment_method, created_at) VALUES (:id, :value, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($methods as $method) {
            $insert->execute(['id' => $groupId, 'value' => $method->value, 'now' => $now]);
        }
    }

    private function hydrate(mixed $row): ?ProviderGroup
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = Row::int($row['id'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);
        $deviceType = DeviceType::tryFrom(Row::str($row['device_type'] ?? ''));

        return ProviderGroup::fromStorage(
            $id,
            Row::int($row['client_id'] ?? null),
            ProviderGroupSlug::of(Row::str($row['slug'] ?? '')),
            Row::str($row['name'] ?? ''),
            Row::bool($row['is_default'] ?? null),
            $deviceType,
            Row::nullableStr($row['currency_code'] ?? null),
            ProviderGroupStatus::from(Row::str($row['status'] ?? 'active')),
            $this->countriesOf($id),
            $this->accountsOf($id),
            $this->purchaseTypesOf($id),
            $this->methodsOf($id),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }

    /**
     * @return list<string>
     */
    private function countriesOf(int $groupId): array
    {
        $statement = $this->pdo->prepare('SELECT country_code FROM provider_group_countries WHERE provider_group_id = :id ORDER BY country_code');
        $statement->execute(['id' => $groupId]);

        $codes = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $codes[] = Row::str($value);
        }

        return $codes;
    }

    /**
     * @return list<ProviderGroupAccount>
     */
    private function accountsOf(int $groupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, provider_account_id, priority, is_enabled
               FROM provider_group_accounts WHERE provider_group_id = :id ORDER BY priority ASC, provider_account_id ASC',
        );
        $statement->execute(['id' => $groupId]);

        $accounts = [];
        while (($row = $statement->fetch()) !== false) {
            if (!\is_array($row)) {
                continue;
            }
            $accounts[] = ProviderGroupAccount::fromStorage(
                Row::int($row['id'] ?? null),
                Row::int($row['provider_account_id'] ?? null),
                Row::int($row['priority'] ?? null),
                Row::bool($row['is_enabled'] ?? null),
            );
        }

        return $accounts;
    }

    /**
     * @return list<PurchaseType>
     */
    private function purchaseTypesOf(int $groupId): array
    {
        $statement = $this->pdo->prepare('SELECT purchase_type FROM provider_group_purchase_types WHERE provider_group_id = :id');
        $statement->execute(['id' => $groupId]);

        $values = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $purchaseType = PurchaseType::tryFrom(Row::str($value));
            if ($purchaseType !== null) {
                $values[] = $purchaseType;
            }
        }

        return $values;
    }

    /**
     * @return list<PaymentMethod>
     */
    private function methodsOf(int $groupId): array
    {
        $statement = $this->pdo->prepare('SELECT payment_method FROM provider_group_methods WHERE provider_group_id = :id');
        $statement->execute(['id' => $groupId]);

        $values = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $method = PaymentMethod::tryFrom(Row::str($value));
            if ($method !== null) {
                $values[] = $method;
            }
        }

        return $values;
    }
}
