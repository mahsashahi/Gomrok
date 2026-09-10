<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackageCountryPurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Packages\Domain\PackageStatus;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see PackageRepository}. `save()` writes the `packages` row then
 * rebuilds the availability child tables (Phase 11) and
 * `package_purchase_capabilities` / `package_country_purchase_capabilities`
 * (Phase 12) delete-and-reinsert. Callers wrap it in a transaction.
 */
final readonly class PdoPackageRepository implements PackageRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(Package $package): void
    {
        if ($package->id() === null) {
            $this->insert($package);
        } else {
            $this->update($package);
        }

        $id = $package->id();
        \assert($id !== null);

        $this->syncStrings($id, 'package_countries', 'country_code', $package->countryCodes());
        $this->syncStrings($id, 'package_currencies', 'currency_code', $package->currencyCodes());
        $this->syncStrings(
            $id,
            'package_payment_methods',
            'payment_method',
            array_map(static fn (PaymentMethod $m): string => $m->value, $package->methods()),
        );
        $this->syncInts($id, 'package_provider_accounts', 'provider_account_id', $package->providerAccountIds());
        $this->syncPurchaseCapabilities($id, $package->purchaseCapabilities());
        $this->syncCountryCapabilities($id, $package->countryPurchaseCapabilities());
    }

    public function findById(int $id): ?Package
    {
        $statement = $this->pdo->prepare('SELECT * FROM packages WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByClientAndCode(int $clientId, string $code): ?Package
    {
        $statement = $this->pdo->prepare('SELECT * FROM packages WHERE client_id = :client_id AND code = :code');
        $statement->execute(['client_id' => $clientId, 'code' => $code]);

        return $this->hydrate($statement->fetch());
    }

    public function existsForClientWithCode(int $clientId, string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM packages WHERE client_id = :client_id AND code = :code LIMIT 1');
        $statement->execute(['client_id' => $clientId, 'code' => $code]);

        return $statement->fetchColumn() !== false;
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM packages WHERE client_id = :client_id ORDER BY code');
        $statement->execute(['client_id' => $clientId]);

        $packages = [];
        while (($row = $statement->fetch()) !== false) {
            $package = $this->hydrate($row);
            if ($package !== null) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    private function insert(Package $package): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO packages
                (client_id, code, name, description, status, metadata, badge, highlighted, client_package_id, created_at, updated_at)
             VALUES
                (:client_id, :code, :name, :description, :status, :metadata, :badge, :highlighted, :client_package_id, :created_at, :updated_at)',
        );
        $statement->execute([
            'client_id' => $package->clientId(),
            'code' => $package->code()->value,
            'name' => $package->name(),
            'description' => $package->description(),
            'status' => $package->status()->value,
            'metadata' => $this->encodeMetadata($package->metadata()),
            'badge' => $package->badge(),
            'highlighted' => $package->highlighted() ? 1 : 0,
            'client_package_id' => $package->clientPackageId(),
            'created_at' => $package->createdAt()->format(self::DT),
            'updated_at' => $package->updatedAt()?->format(self::DT),
        ]);

        $package->assignId((int) $this->pdo->lastInsertId());
    }

    private function update(Package $package): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE packages SET name = :name, description = :description, status = :status,
                metadata = :metadata, badge = :badge, highlighted = :highlighted,
                client_package_id = :client_package_id, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $package->id(),
            'name' => $package->name(),
            'description' => $package->description(),
            'status' => $package->status()->value,
            'metadata' => $this->encodeMetadata($package->metadata()),
            'badge' => $package->badge(),
            'highlighted' => $package->highlighted() ? 1 : 0,
            'client_package_id' => $package->clientPackageId(),
            'updated_at' => $package->updatedAt()?->format(self::DT),
        ]);
    }

    /**
     * @param list<string> $values
     */
    private function syncStrings(int $packageId, string $table, string $column, array $values): void
    {
        $this->pdo->prepare("DELETE FROM {$table} WHERE package_id = :id")->execute(['id' => $packageId]);
        if ($values === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$table} (package_id, {$column}, created_at) VALUES (:id, :value, :now)",
        );
        $now = gmdate(self::DT);
        foreach ($values as $value) {
            $insert->execute(['id' => $packageId, 'value' => $value, 'now' => $now]);
        }
    }

    /**
     * @param list<int> $values
     */
    private function syncInts(int $packageId, string $table, string $column, array $values): void
    {
        $this->pdo->prepare("DELETE FROM {$table} WHERE package_id = :id")->execute(['id' => $packageId]);
        if ($values === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$table} (package_id, {$column}, created_at) VALUES (:id, :value, :now)",
        );
        $now = gmdate(self::DT);
        foreach ($values as $value) {
            $insert->execute(['id' => $packageId, 'value' => $value, 'now' => $now]);
        }
    }

    /**
     * @param list<PackagePurchaseCapability> $capabilities
     */
    private function syncPurchaseCapabilities(int $packageId, array $capabilities): void
    {
        $this->pdo->prepare('DELETE FROM package_purchase_capabilities WHERE package_id = :id')->execute(['id' => $packageId]);
        if ($capabilities === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO package_purchase_capabilities
                (package_id, purchase_type, has_trial, trial_days, duration_months, created_at, updated_at)
             VALUES (:id, :type, :has_trial, :trial_days, :duration_months, :now, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($capabilities as $capability) {
            $insert->execute([
                'id' => $packageId,
                'type' => $capability->purchaseType->value,
                'has_trial' => $capability->hasTrial ? 1 : 0,
                'trial_days' => $capability->trialDays,
                'duration_months' => $capability->durationMonths,
                'now' => $now,
            ]);
        }
    }

    /**
     * @param list<PackageCountryPurchaseCapability> $overrides
     */
    private function syncCountryCapabilities(int $packageId, array $overrides): void
    {
        $this->pdo->prepare('DELETE FROM package_country_purchase_capabilities WHERE package_id = :id')->execute(['id' => $packageId]);
        if ($overrides === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO package_country_purchase_capabilities (package_id, country_code, purchase_type, created_at)
             VALUES (:id, :country, :type, :now)',
        );
        $now = gmdate(self::DT);
        foreach ($overrides as $override) {
            $insert->execute([
                'id' => $packageId,
                'country' => $override->countryCode,
                'type' => $override->purchaseType->value,
                'now' => $now,
            ]);
        }
    }

    private function hydrate(mixed $row): ?Package
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = Row::int($row['id'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return Package::fromStorage(
            $id,
            Row::int($row['client_id'] ?? null),
            PackageCode::of(Row::str($row['code'] ?? '')),
            Row::str($row['name'] ?? ''),
            Row::nullableStr($row['description'] ?? null),
            PackageStatus::from(Row::str($row['status'] ?? 'active')),
            $this->decodeMetadata(Row::nullableStr($row['metadata'] ?? null)),
            Row::nullableStr($row['badge'] ?? null),
            Row::bool($row['highlighted'] ?? null),
            Row::nullableStr($row['client_package_id'] ?? null),
            $this->stringsOf($id, 'package_countries', 'country_code'),
            $this->stringsOf($id, 'package_currencies', 'currency_code'),
            $this->methodsOf($id),
            $this->providerAccountIdsOf($id),
            $this->capabilitiesOf($id),
            $this->countryCapabilitiesOf($id),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }

    /**
     * @return list<PackagePurchaseCapability>
     */
    private function capabilitiesOf(int $packageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT purchase_type, has_trial, trial_days, duration_months
               FROM package_purchase_capabilities WHERE package_id = :id ORDER BY purchase_type',
        );
        $statement->execute(['id' => $packageId]);

        $capabilities = [];
        while (($row = $statement->fetch()) !== false) {
            if (!\is_array($row)) {
                continue;
            }
            $type = PurchaseType::tryFrom(Row::str($row['purchase_type'] ?? ''));
            if ($type === null) {
                continue;
            }
            $capabilities[] = PackagePurchaseCapability::of(
                $type,
                Row::bool($row['has_trial'] ?? null),
                Row::nullableInt($row['trial_days'] ?? null),
                Row::nullableInt($row['duration_months'] ?? null),
            );
        }

        return $capabilities;
    }

    /**
     * @return list<PackageCountryPurchaseCapability>
     */
    private function countryCapabilitiesOf(int $packageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT country_code, purchase_type FROM package_country_purchase_capabilities
              WHERE package_id = :id ORDER BY country_code, purchase_type',
        );
        $statement->execute(['id' => $packageId]);

        $overrides = [];
        while (($row = $statement->fetch()) !== false) {
            if (!\is_array($row)) {
                continue;
            }
            $type = PurchaseType::tryFrom(Row::str($row['purchase_type'] ?? ''));
            if ($type === null) {
                continue;
            }
            $overrides[] = new PackageCountryPurchaseCapability(Row::str($row['country_code'] ?? ''), $type);
        }

        return $overrides;
    }

    /**
     * @return list<string>
     */
    private function stringsOf(int $packageId, string $table, string $column): array
    {
        $statement = $this->pdo->prepare("SELECT {$column} FROM {$table} WHERE package_id = :id ORDER BY {$column}");
        $statement->execute(['id' => $packageId]);

        $values = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $values[] = Row::str($value);
        }

        return $values;
    }

    /**
     * @return list<PaymentMethod>
     */
    private function methodsOf(int $packageId): array
    {
        $values = [];
        foreach ($this->stringsOf($packageId, 'package_payment_methods', 'payment_method') as $value) {
            $method = PaymentMethod::tryFrom($value);
            if ($method !== null) {
                $values[] = $method;
            }
        }

        return $values;
    }

    /**
     * @return list<int>
     */
    private function providerAccountIdsOf(int $packageId): array
    {
        $statement = $this->pdo->prepare('SELECT provider_account_id FROM package_provider_accounts WHERE package_id = :id ORDER BY provider_account_id');
        $statement->execute(['id' => $packageId]);

        $ids = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $ids[] = Row::int($value);
        }

        return $ids;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function encodeMetadata(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }
        $json = json_encode($metadata);

        return $json === false ? null : $json;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeMetadata(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
