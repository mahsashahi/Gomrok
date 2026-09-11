<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

/**
 * The resolved price for a package in a market context: pricing group match →
 * group-package row → baseline / conversion / override (Phase 13), then the
 * assigned A/B price list (Phase 15 — control by default), then the
 * most-specific matching `price_rules` row (Phase 14). Plus the effective
 * display fields.
 *
 * Phases 16–17 layer the voucher discount, Phase 18 tax/fee — before the final
 * payable amount is snapshotted on a payment.
 */
final readonly class ResolvedPrice
{
    /**
     * @param list<string> $appliedDimensions the dimensions the winning price rule pinned (empty when no rule applied)
     */
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
        public ?int $appliedRuleId = null,
        public array $appliedDimensions = [],
        public ?int $priceListId = null,
        public ?string $priceListName = null,
        public string $priceListFactor = '1.0000',
    ) {
    }

    /**
     * Stamp the assigned A/B price list. `$source` stays the caller's current
     * source for a neutral (control / factor 1) list, or {@see PriceSource::PriceList}
     * when the list actually moved the amount.
     */
    public function withList(int $amountMinor, string $amountDecimal, PriceSource $source, int $priceListId, string $priceListName, string $priceListFactor): self
    {
        return new self(
            $this->packageId,
            $this->packageCode,
            $amountMinor,
            $amountDecimal,
            $this->currencyCode,
            $source,
            $this->pricingGroupSlug,
            $this->pricingGroupIsDefault,
            $this->name,
            $this->badge,
            $this->highlighted,
            $this->displayOrder,
            $this->appliedRuleId,
            $this->appliedDimensions,
            $priceListId,
            $priceListName,
            $priceListFactor,
        );
    }

    /**
     * @param list<string> $dimensions
     */
    public function withRule(int $amountMinor, string $amountDecimal, int $ruleId, array $dimensions): self
    {
        return new self(
            $this->packageId,
            $this->packageCode,
            $amountMinor,
            $amountDecimal,
            $this->currencyCode,
            PriceSource::DimensionOverride,
            $this->pricingGroupSlug,
            $this->pricingGroupIsDefault,
            $this->name,
            $this->badge,
            $this->highlighted,
            $this->displayOrder,
            $ruleId,
            $dimensions,
            $this->priceListId,
            $this->priceListName,
            $this->priceListFactor,
        );
    }

    /**
     * The full resolved-price payload for a {@see PricingDecisionSnapshot}
     * (Phase 18) — everything needed to reconstruct what was decided and why,
     * without a repository lookup.
     *
     * @return array<string, scalar|null|list<string>>
     */
    public function toArray(): array
    {
        return [
            'package_id' => $this->packageId,
            'package_code' => $this->packageCode,
            'amount_minor' => $this->amountMinor,
            'amount' => $this->amountDecimal,
            'currency_code' => $this->currencyCode,
            'source' => $this->source->value,
            'pricing_group_slug' => $this->pricingGroupSlug,
            'pricing_group_is_default' => $this->pricingGroupIsDefault,
            'name' => $this->name,
            'badge' => $this->badge,
            'highlighted' => $this->highlighted,
            'display_order' => $this->displayOrder,
            'applied_rule_id' => $this->appliedRuleId,
            'applied_dimensions' => $this->appliedDimensions,
            'price_list_id' => $this->priceListId,
            'price_list_name' => $this->priceListName,
            'price_list_factor' => $this->priceListFactor,
        ];
    }
}
