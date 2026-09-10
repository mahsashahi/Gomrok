<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Pricing\Domain\PriceRule;
use Gomrok\Modules\Pricing\Domain\PriceRuleRepository;

final class InMemoryPriceRuleRepository implements PriceRuleRepository
{
    /** @var array<int, PriceRule> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(PriceRule $rule): void
    {
        if ($rule->id() === null) {
            $rule->assignId($this->nextId++);
        }
        $id = $rule->id();
        \assert($id !== null);
        $this->byId[$id] = $rule;
    }

    public function findById(int $id): ?PriceRule
    {
        return $this->byId[$id] ?? null;
    }

    public function delete(int $id): bool
    {
        if (!isset($this->byId[$id])) {
            return false;
        }
        unset($this->byId[$id]);

        return true;
    }

    public function forClientPackage(int $clientId, int $packageId): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (PriceRule $r): bool => $r->clientId() === $clientId && $r->packageId() === $packageId,
        ));
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
        foreach ($this->byId as $rule) {
            if (
                $rule->packageId() === $packageId
                && $rule->pricingGroupId() === $pricingGroupId
                && $rule->countryCode() === $countryCode
                && $rule->providerAccountId() === $providerAccountId
                && $rule->paymentMethod()?->value === $paymentMethod
                && $rule->purchaseType()?->value === $purchaseType
                && $rule->subscriptionInterval()?->value === $subscriptionInterval
                && $rule->currencyCode() === $currencyCode
            ) {
                return $rule;
            }
        }

        return null;
    }
}
