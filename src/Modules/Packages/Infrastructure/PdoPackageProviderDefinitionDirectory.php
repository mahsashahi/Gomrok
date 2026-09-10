<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Infrastructure;

use Gomrok\Modules\Packages\Application\PackageProviderDefinitionDirectory;
use Gomrok\Modules\Packages\Application\PackageProviderDefinitionSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * Read-side {@see PackageProviderDefinitionDirectory}.
 */
final readonly class PdoPackageProviderDefinitionDirectory implements PackageProviderDefinitionDirectory
{
    private const COLUMNS = 'id, package_id, provider_account_id, provider_side_name, remote_id, sync_state, last_error';

    public function __construct(private PDO $pdo)
    {
    }

    public function forPackage(int $packageId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM package_provider_definitions WHERE package_id = :id ORDER BY provider_account_id',
        );
        $statement->execute(['id' => $packageId]);

        $summaries = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $summaries[] = $this->toSummary($row);
            }
        }

        return $summaries;
    }

    public function find(int $packageId, int $providerAccountId): ?PackageProviderDefinitionSummary
    {
        $statement = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM package_provider_definitions WHERE package_id = :p AND provider_account_id = :a',
        );
        $statement->execute(['p' => $packageId, 'a' => $providerAccountId]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): PackageProviderDefinitionSummary
    {
        return new PackageProviderDefinitionSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::nullableStr($row['provider_side_name'] ?? null),
            Row::nullableStr($row['remote_id'] ?? null),
            Row::str($row['sync_state'] ?? ''),
            Row::nullableStr($row['last_error'] ?? null),
        );
    }
}
