<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Infrastructure;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\PackageSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;
use PDOStatement;

/**
 * Read-side {@see PackageDirectory} — a projection over `packages` aggregating
 * the four availability child tables with subselect `GROUP_CONCAT`.
 */
final readonly class PdoPackageDirectory implements PackageDirectory
{
    private const BASE = <<<'SQL'
        SELECT p.id, p.client_id, p.code, p.name, p.description, p.status, p.metadata,
               (SELECT GROUP_CONCAT(c.country_code ORDER BY c.country_code)
                  FROM package_countries c WHERE c.package_id = p.id) AS countries,
               (SELECT GROUP_CONCAT(cu.currency_code ORDER BY cu.currency_code)
                  FROM package_currencies cu WHERE cu.package_id = p.id) AS currencies,
               (SELECT GROUP_CONCAT(m.payment_method ORDER BY m.payment_method)
                  FROM package_payment_methods m WHERE m.package_id = p.id) AS methods,
               (SELECT GROUP_CONCAT(pa.provider_account_id ORDER BY pa.provider_account_id)
                  FROM package_provider_accounts pa WHERE pa.package_id = p.id) AS provider_account_ids
          FROM packages p
        SQL;

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?PackageSummary
    {
        $statement = $this->pdo->prepare(self::BASE . ' WHERE p.id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function find(int $clientId, string $code): ?PackageSummary
    {
        $statement = $this->pdo->prepare(self::BASE . ' WHERE p.client_id = :client_id AND p.code = :code');
        $statement->execute(['client_id' => $clientId, 'code' => $code]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare(self::BASE . ' WHERE p.client_id = :client_id ORDER BY p.code');
        $statement->execute(['client_id' => $clientId]);

        return $this->collect($statement);
    }

    /**
     * @return list<PackageSummary>
     */
    private function collect(PDOStatement $statement): array
    {
        $summaries = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $summaries[] = $this->toSummary($row);
            }
        }

        return $summaries;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): PackageSummary
    {
        return new PackageSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::str($row['code'] ?? ''),
            Row::str($row['name'] ?? ''),
            Row::nullableStr($row['description'] ?? null),
            Row::str($row['status'] ?? ''),
            $this->decodeMetadata(Row::nullableStr($row['metadata'] ?? null)),
            $this->splitStrings(Row::nullableStr($row['countries'] ?? null)),
            $this->splitStrings(Row::nullableStr($row['currencies'] ?? null)),
            $this->splitStrings(Row::nullableStr($row['methods'] ?? null)),
            $this->splitInts(Row::nullableStr($row['provider_account_ids'] ?? null)),
        );
    }

    /**
     * @return list<string>
     */
    private function splitStrings(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $csv), static fn (string $s): bool => $s !== ''));
    }

    /**
     * @return list<int>
     */
    private function splitInts(?string $csv): array
    {
        return array_map(static fn (string $s): int => (int) $s, $this->splitStrings($csv));
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
