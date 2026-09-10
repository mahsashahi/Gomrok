<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * The Phase 13 resolved price for a package in a market context: the pricing
 * group match → group-package row → baseline / conversion / override, plus the
 * effective display fields after group overrides.
 *
 * This is the *baseline* — Phase 14 layers dimension overrides, Phase 15 the A/B
 * factor, Phases 16–17 the voucher discount, Phase 18 tax/fee — before the final
 * payable amount is snapshotted on a payment.
 */
final readonly class ResolvedPrice
{
    public function __construct(
        public int $packageId,
        public string $packageCode,
        public int $amountMinor,
        public string $amountDecimal,
        public string $currencyCode,
        public PriceSource $source,
        public string $pricingGroupSlug,
        public bool $pricingGroupIsDefault,
        public string $name,
        public ?string $badge,
        public bool $highlighted,
        public int $displayOrder,
    ) {
    }
}
