<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinition;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageProviderSyncState;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see PackageProviderDefinitionRepository}. Callers wrap writes in a
 * transaction.
 */
final readonly class PdoPackageProviderDefinitionRepository implements PackageProviderDefinitionRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(PackageProviderDefinition $definition): void
    {
        if ($definition->id() === null) {
            $this->insert($definition);

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE package_provider_definitions SET
                provider_side_name = :name, remote_id = :remote_id, sync_state = :sync_state,
                last_synced_at = :last_synced_at, last_error = :last_error, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $definition->id(),
            'name' => $definition->providerSideName(),
            'remote_id' => $definition->remoteId(),
            'sync_state' => $definition->syncState()->value,
            'last_synced_at' => $definition->lastSyncedAt()?->format(self::DT),
            'last_error' => $definition->lastError(),
            'updated_at' => $definition->updatedAt()?->format(self::DT),
        ]);
    }

    public function findById(int $id): ?PackageProviderDefinition
    {
        $statement = $this->pdo->prepare('SELECT * FROM package_provider_definitions WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByPackageAndAccount(int $packageId, int $providerAccountId): ?PackageProviderDefinition
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM package_provider_definitions WHERE package_id = :p AND provider_account_id = :a',
        );
        $statement->execute(['p' => $packageId, 'a' => $providerAccountId]);

        return $this->hydrate($statement->fetch());
    }

    public function forPackage(int $packageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM package_provider_definitions WHERE package_id = :id ORDER BY provider_account_id',
        );
        $statement->execute(['id' => $packageId]);

        $definitions = [];
        while (($row = $statement->fetch()) !== false) {
            $definition = $this->hydrate($row);
            if ($definition !== null) {
                $definitions[] = $definition;
            }
        }

        return $definitions;
    }

    public function markStaleForPackage(int $packageId, DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare(
            "UPDATE package_provider_definitions
                SET sync_state = 'drift', updated_at = :now
              WHERE package_id = :id AND sync_state = 'synced'",
        );
        $statement->execute(['id' => $packageId, 'now' => $now->format(self::DT)]);

        return $statement->rowCount();
    }

    private function insert(PackageProviderDefinition $definition): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO package_provider_definitions
                (package_id, provider_account_id, provider_side_name, remote_id, sync_state,
                 last_synced_at, last_error, created_at, updated_at)
             VALUES (:package_id, :provider_account_id, :name, :remote_id, :sync_state,
                 :last_synced_at, :last_error, :created_at, :updated_at)',
        );
        $statement->execute([
            'package_id' => $definition->packageId(),
            'provider_account_id' => $definition->providerAccountId(),
            'name' => $definition->providerSideName(),
            'remote_id' => $definition->remoteId(),
            'sync_state' => $definition->syncState()->value,
            'last_synced_at' => $definition->lastSyncedAt()?->format(self::DT),
            'last_error' => $definition->lastError(),
            'created_at' => $definition->createdAt()->format(self::DT),
            'updated_at' => $definition->updatedAt()?->format(self::DT),
        ]);

        $definition->assignId((int) $this->pdo->lastInsertId());
    }

    private function hydrate(mixed $row): ?PackageProviderDefinition
    {
        if (!\is_array($row)) {
            return null;
        }

        $lastSyncedAt = Row::nullableStr($row['last_synced_at'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return PackageProviderDefinition::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::nullableStr($row['provider_side_name'] ?? null),
            Row::nullableStr($row['remote_id'] ?? null),
            PackageProviderSyncState::from(Row::str($row['sync_state'] ?? 'not_created')),
            $lastSyncedAt !== null ? new DateTimeImmutable($lastSyncedAt) : null,
            Row::nullableStr($row['last_error'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
