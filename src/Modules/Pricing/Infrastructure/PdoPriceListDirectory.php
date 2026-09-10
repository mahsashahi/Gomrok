<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use Gomrok\Modules\Pricing\Application\PriceListDirectory;
use Gomrok\Modules\Pricing\Application\PriceListSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPriceListDirectory implements PriceListDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forGroup(int $pricingGroupId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM price_lists WHERE pricing_group_id = :g ORDER BY is_control DESC, name ASC',
        );
        $statement->execute(['g' => $pricingGroupId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $out[] = $this->toSummary($row);
            }
        }

        return $out;
    }

    public function findById(int $priceListId): ?PriceListSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_lists WHERE id = :id');
        $statement->execute(['id' => $priceListId]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): PriceListSummary
    {
        $id = Row::int($row['id'] ?? null);

        $prices = $this->pdo->prepare('SELECT package_id, amount_minor, currency_code FROM price_list_packages WHERE price_list_id = :l ORDER BY package_id ASC');
        $prices->execute(['l' => $id]);

        $packagePrices = [];
        while (($p = $prices->fetch()) !== false) {
            if (!\is_array($p)) {
                continue;
            }
            $packagePrices[] = [
                'package_id' => Row::int($p['package_id'] ?? null),
                'amount_minor' => Row::int($p['amount_minor'] ?? null),
                'currency' => Row::str($p['currency_code'] ?? ''),
            ];
        }

        return new PriceListSummary(
            $id,
            Row::int($row['client_id'] ?? null),
            Row::int($row['pricing_group_id'] ?? null),
            Row::str($row['name'] ?? ''),
            Row::bool($row['is_control'] ?? null),
            Row::str($row['factor'] ?? '1.0000'),
            Row::bool($row['is_enabled'] ?? null),
            $packagePrices,
        );
    }
}
