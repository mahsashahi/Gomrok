<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * Read-side {@see ProviderAccountDirectory} — a projection query joining
 * `provider_accounts` with `provider_types` and aggregating the child rows. No
 * secret is selected.
 */
final readonly class PdoProviderAccountDirectory implements ProviderAccountDirectory
{
    private const BASE = <<<'SQL'
        SELECT pa.id, pa.client_id, pa.slug, pa.name, pt.code AS provider_type_code,
               pa.mode, pa.status, pa.public_key, pa.secret_last_four,
               (SELECT GROUP_CONCAT(c.country_code ORDER BY c.country_code)
                  FROM provider_account_countries c WHERE c.provider_account_id = pa.id) AS countries,
               (SELECT GROUP_CONCAT(m.payment_method ORDER BY m.payment_method)
                  FROM provider_account_methods m WHERE m.provider_account_id = pa.id) AS methods,
               (SELECT COUNT(*) FROM provider_account_endpoints e
                  WHERE e.provider_account_id = pa.id AND e.is_active = 1) AS active_endpoints
          FROM provider_accounts pa
          JOIN provider_types pt ON pt.id = pa.provider_type_id
        SQL;

    public function __construct(private PDO $pdo)
    {
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare(self::BASE . ' WHERE pa.client_id = :client_id ORDER BY pa.slug');
        $statement->execute(['client_id' => $clientId]);

        return $this->collect($statement);
    }

    public function find(int $clientId, string $slug): ?ProviderAccountSummary
    {
        $statement = $this->pdo->prepare(self::BASE . ' WHERE pa.client_id = :client_id AND pa.slug = :slug');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function candidates(int $clientId, string $providerTypeCode, ProviderAccountMode $mode): array
    {
        $statement = $this->pdo->prepare(
            self::BASE . " WHERE pa.client_id = :client_id AND pt.code = :code AND pa.mode = :mode AND pa.status = 'active' ORDER BY pa.slug",
        );
        $statement->execute(['client_id' => $clientId, 'code' => $providerTypeCode, 'mode' => $mode->value]);

        return $this->collect($statement);
    }

    /**
     * @return list<ProviderAccountSummary>
     */
    private function collect(\PDOStatement $statement): array
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
    private function toSummary(array $row): ProviderAccountSummary
    {
        return new ProviderAccountSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::str($row['slug'] ?? ''),
            Row::str($row['name'] ?? ''),
            Row::str($row['provider_type_code'] ?? ''),
            Row::str($row['mode'] ?? ''),
            Row::str($row['status'] ?? ''),
            Row::nullableStr($row['public_key'] ?? null),
            Row::str($row['secret_last_four'] ?? ''),
            $this->split(Row::nullableStr($row['countries'] ?? null)),
            $this->split(Row::nullableStr($row['methods'] ?? null)),
            Row::int($row['active_endpoints'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function split(?string $csv): array
    {
        if ($csv === null || $csv === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $csv), static fn (string $s): bool => $s !== ''));
    }
}
