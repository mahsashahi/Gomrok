<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\PriceRule;
use Gomrok\Modules\Pricing\Domain\PriceRuleRepository;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPriceRuleRepository implements PriceRuleRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(PriceRule $rule): void
    {
        if ($rule->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO price_rules
                    (client_id, package_id, pricing_group_id, country_code, provider_account_id,
                     payment_method, purchase_type, subscription_interval, currency_code,
                     is_available, amount_minor, created_at, updated_at)
                 VALUES
                    (:client_id, :package_id, :pricing_group_id, :country_code, :provider_account_id,
                     :payment_method, :purchase_type, :subscription_interval, :currency_code,
                     :is_available, :amount_minor, :created_at, :updated_at)',
            );
            $statement->execute($this->params($rule) + [
                'created_at' => $rule->createdAt()->format(self::DT),
                'updated_at' => $rule->updatedAt()?->format(self::DT),
            ]);
            $rule->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE price_rules SET is_available = :is_available, amount_minor = :amount_minor, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $rule->id(),
            'is_available' => $rule->isAvailable() ? 1 : 0,
            'amount_minor' => $rule->amountMinor(),
            'updated_at' => $rule->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
        ]);
    }

    public function findById(int $id): ?PriceRule
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_rules WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function delete(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM price_rules WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }

    public function forClientPackage(int $clientId, int $packageId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM price_rules WHERE client_id = :c AND package_id = :p ORDER BY id');
        $statement->execute(['c' => $clientId, 'p' => $packageId]);

        $rules = [];
        while (($row = $statement->fetch()) !== false) {
            $rule = $this->hydrate($row);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    public function findByDimensions(
        int $packageId,
        ?int $pricingGroupId,
        ?string $countryCode,
        ?int $providerAccountId,
        ?string $paymentMethod,
        ?string $purchaseType,
        ?string $subscriptionInterval,
        ?string $currencyCode,
    ): ?PriceRule {
        $statement = $this->pdo->prepare(
            'SELECT * FROM price_rules WHERE package_id = :package_id
               AND pricing_group_id <=> :pricing_group_id
               AND country_code <=> :country_code
               AND provider_account_id <=> :provider_account_id
               AND payment_method <=> :payment_method
               AND purchase_type <=> :purchase_type
               AND subscription_interval <=> :subscription_interval
               AND currency_code <=> :currency_code',
        );
        $statement->execute([
            'package_id' => $packageId,
            'pricing_group_id' => $pricingGroupId,
            'country_code' => $countryCode,
            'provider_account_id' => $providerAccountId,
            'payment_method' => $paymentMethod,
            'purchase_type' => $purchaseType,
            'subscription_interval' => $subscriptionInterval,
            'currency_code' => $currencyCode,
        ]);

        return $this->hydrate($statement->fetch());
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(PriceRule $rule): array
    {
        return [
            'client_id' => $rule->clientId(),
            'package_id' => $rule->packageId(),
            'pricing_group_id' => $rule->pricingGroupId(),
            'country_code' => $rule->countryCode(),
            'provider_account_id' => $rule->providerAccountId(),
            'payment_method' => $rule->paymentMethod()?->value,
            'purchase_type' => $rule->purchaseType()?->value,
            'subscription_interval' => $rule->subscriptionInterval()?->value,
            'currency_code' => $rule->currencyCode(),
            'is_available' => $rule->isAvailable() ? 1 : 0,
            'amount_minor' => $rule->amountMinor(),
        ];
    }

    private function hydrate(mixed $row): ?PriceRule
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return PriceRule::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::nullableInt($row['pricing_group_id'] ?? null),
            Row::nullableStr($row['country_code'] ?? null),
            Row::nullableInt($row['provider_account_id'] ?? null),
            PaymentMethod::tryFrom(Row::str($row['payment_method'] ?? '')),
            PurchaseType::tryFrom(Row::str($row['purchase_type'] ?? '')),
            SubscriptionInterval::tryFrom(Row::str($row['subscription_interval'] ?? '')),
            Row::nullableStr($row['currency_code'] ?? null),
            Row::bool($row['is_available'] ?? null),
            Row::nullableInt($row['amount_minor'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
