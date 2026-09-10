<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * Persistence port for {@see PriceRule}.
 */
interface PriceRuleRepository
{
    /** Insert or update (unique on the dimension tuple). */
    public function save(PriceRule $rule): void;

    public function findById(int $id): ?PriceRule;

    public function delete(int $id): bool;

    /**
     * Every rule for a `(client, package)` — the resolver filters + sorts in
     * memory.
     *
     * @return list<PriceRule>
     */
    public function forClientPackage(int $clientId, int $packageId): array;

    /**
     * Match an exact dimension tuple (for upsert).
     */
    public function findByDimensions(
        int $packageId,
        ?int $pricingGroupId,
        ?string $countryCode,
        ?int $providerAccountId,
        ?string $paymentMethod,
        ?string $purchaseType,
        ?string $subscriptionInterval,
        ?string $currencyCode,
    ): ?PriceRule;
}
