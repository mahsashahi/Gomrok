<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use Gomrok\Modules\Pricing\Application\PriceRuleDirectory;
use Gomrok\Modules\Pricing\Application\PriceRuleSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPriceRuleDirectory implements PriceRuleDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forClientPackage(int $clientId, int $packageId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_rules WHERE client_id = :c AND package_id = :p ORDER BY id');
        $statement->execute(['c' => $clientId, 'p' => $packageId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $out[] = $this->toSummary($row);
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): PriceRuleSummary
    {
        $dimensions = [
            'subscription_interval' => Row::nullableStr($row['subscription_interval'] ?? null) !== null,
            'purchase_type' => Row::nullableStr($row['purchase_type'] ?? null) !== null,
            'payment_method' => Row::nullableStr($row['payment_method'] ?? null) !== null,
            'provider_account_id' => Row::nullableInt($row['provider_account_id'] ?? null) !== null,
            'currency_code' => Row::nullableStr($row['currency_code'] ?? null) !== null,
            'country_code' => Row::nullableStr($row['country_code'] ?? null) !== null,
            'pricing_group_id' => Row::nullableInt($row['pricing_group_id'] ?? null) !== null,
        ];

        return new PriceRuleSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::nullableInt($row['pricing_group_id'] ?? null),
            Row::nullableStr($row['country_code'] ?? null),
            Row::nullableInt($row['provider_account_id'] ?? null),
            Row::nullableStr($row['payment_method'] ?? null),
            Row::nullableStr($row['purchase_type'] ?? null),
            Row::nullableStr($row['subscription_interval'] ?? null),
            Row::nullableStr($row['currency_code'] ?? null),
            Row::bool($row['is_available'] ?? null),
            Row::nullableInt($row['amount_minor'] ?? null),
            \count(array_filter($dimensions)),
        );
    }
}
