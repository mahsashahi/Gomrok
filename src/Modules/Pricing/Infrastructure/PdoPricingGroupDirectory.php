<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;
use Gomrok\Modules\Pricing\Application\PricingGroupSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPricingGroupDirectory implements PricingGroupDirectory
{
    private const BASE = <<<'SQL'
        SELECT g.id, g.client_id, g.slug, g.name, g.priority, g.device_type, g.currency_code,
               g.is_default, g.status,
               (SELECT GROUP_CONCAT(c.country_code ORDER BY c.country_code)
                  FROM pricing_group_countries c WHERE c.pricing_group_id = g.id) AS countries
          FROM pricing_groups g
        SQL;

    public function __construct(private PDO $pdo)
    {
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare(self::BASE . ' WHERE g.client_id = :client_id ORDER BY g.is_default ASC, g.priority ASC, g.slug ASC');
        $statement->execute(['client_id' => $clientId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $out[] = $this->toSummary($row);
            }
        }

        return $out;
    }

    public function find(int $clientId, string $slug): ?PricingGroupSummary
    {
        $statement = $this->pdo->prepare(self::BASE . ' WHERE g.client_id = :client_id AND g.slug = :slug');
        $statement->execute(['client_id' => $clientId, 'slug' => $slug]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): PricingGroupSummary
    {
        $countries = Row::nullableStr($row['countries'] ?? null);

        return new PricingGroupSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::str($row['slug'] ?? ''),
            Row::str($row['name'] ?? ''),
            Row::int($row['priority'] ?? null),
            Row::nullableStr($row['device_type'] ?? null),
            Row::str($row['currency_code'] ?? ''),
            Row::bool($row['is_default'] ?? null),
            Row::str($row['status'] ?? ''),
            $countries === null || $countries === '' ? [] : array_values(array_filter(explode(',', $countries), static fn (string $s): bool => $s !== '')),
        );
    }
}
