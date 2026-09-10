<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceRule;

/**
 * Upsert a price rule for a `(client, package)` by its exact dimension tuple.
 * Any dimension left null is a wildcard. `isAvailable = false` marks the
 * combination as not sold (amount / currency must then be null).
 */
final readonly class SetPriceRuleCommand
{
    public function __construct(
        public int $clientId,
        public int $packageId,
        public ?int $pricingGroupId = null,
        public ?string $countryCode = null,
        public ?int $providerAccountId = null,
        public ?string $paymentMethod = null,
        public ?string $purchaseType = null,
        public ?string $subscriptionInterval = null,
        public ?string $currencyCode = null,
        public bool $isAvailable = true,
        public ?int $amountMinor = null,
        public ?int $actorId = null,
    ) {
    }
}
