<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityDimension;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRule;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRuleRepository;

/**
 * Evaluates a {@see Voucher} against a {@see VoucherContext} and reports
 * **every** unmet condition (Phase 16 Q4), not just the first one:
 *
 *   - status, validity window, client scope;
 *   - every {@see VoucherEligibilityDimension} the voucher restricts;
 *   - discount applicability (a `none`-default voucher needs a currency override);
 *   - minimum purchase (same-currency comparison only);
 *   - first-purchase-only;
 *   - the **global** usage cap (`redeemed_count` vs. `max_total_redemptions`).
 *
 * Per-user / per-client caps need `voucher_redemptions` and are Phase 17 (see
 * {@see VoucherUsagePort}, not called here).
 */
final readonly class VoucherEligibilityEvaluator
{
    public function __construct(
        private VoucherEligibilityRuleRepository $rules,
        private VoucherCurrencyDiscountRepository $currencyDiscounts,
    ) {
    }

    public function evaluate(Voucher $voucher, VoucherContext $context): VoucherEligibility
    {
        $reasons = [];

        if (!$voucher->isActive()) {
            $reasons[] = 'voucher.disabled';
        }
        if ($voucher->clientId() !== $context->clientId) {
            $reasons[] = 'voucher.wrong_client';
        }
        if ($voucher->validFrom() !== null && $context->now < $voucher->validFrom()) {
            $reasons[] = 'voucher.not_yet_valid';
        }
        if ($voucher->validUntil() !== null && $context->now > $voucher->validUntil()) {
            $reasons[] = 'voucher.expired';
        }

        $voucherId = $voucher->id();
        $byDimension = $voucherId !== null ? $this->groupByDimension($this->rules->forVoucher($voucherId)) : [];

        $this->checkDimension($byDimension, $reasons, VoucherEligibilityDimension::Country, $context->country !== null ? strtoupper($context->country) : null, 'voucher.country_not_eligible');
        $this->checkDimension($byDimension, $reasons, VoucherEligibilityDimension::Currency, $context->currency !== null ? strtoupper($context->currency) : null, 'voucher.currency_not_eligible');
        $this->checkDimension($byDimension, $reasons, VoucherEligibilityDimension::Package, $context->packageId !== null ? (string) $context->packageId : null, 'voucher.package_not_eligible');
        $this->checkDimension($byDimension, $reasons, VoucherEligibilityDimension::ProviderAccount, $context->providerAccountId !== null ? (string) $context->providerAccountId : null, 'voucher.provider_not_eligible');
        $this->checkDimension($byDimension, $reasons, VoucherEligibilityDimension::PaymentMethod, $context->paymentMethod?->value, 'voucher.method_not_eligible');
        $this->checkDimension($byDimension, $reasons, VoucherEligibilityDimension::PurchaseType, $context->purchaseType?->value, 'voucher.purchase_type_not_eligible');
        $this->checkDimension($byDimension, $reasons, VoucherEligibilityDimension::SubscriptionInterval, $context->subscriptionInterval?->value, 'voucher.interval_not_eligible');

        if ($voucher->defaultDiscountType() === DefaultDiscountType::None) {
            $hasOverride = $voucherId !== null && $context->currency !== null
                && $this->currencyDiscounts->find($voucherId, $context->currency) !== null;
            if (!$hasOverride) {
                $reasons[] = 'voucher.no_discount_for_currency';
            }
        }

        if ($voucher->minPurchaseMinor() !== null && $context->amountCurrency !== null && $context->amountCurrency === $voucher->minPurchaseCurrency()) {
            if ($context->amountMinor === null || $context->amountMinor < $voucher->minPurchaseMinor()) {
                $reasons[] = 'voucher.below_minimum';
            }
        }

        if ($voucher->firstPurchaseOnly()) {
            if ($context->isFirstPurchase === null) {
                $reasons[] = 'voucher.first_purchase_unknown';
            } elseif (!$context->isFirstPurchase) {
                $reasons[] = 'voucher.not_first_purchase';
            }
        }

        if ($voucher->maxTotalRedemptions() !== null && $voucher->redeemedCount() >= $voucher->maxTotalRedemptions()) {
            $reasons[] = 'voucher.exhausted';
        }

        return VoucherEligibility::failing($reasons);
    }

    /**
     * @param list<VoucherEligibilityRule> $rules
     *
     * @return array<string, list<string>>
     */
    private function groupByDimension(array $rules): array
    {
        $byDimension = [];
        foreach ($rules as $rule) {
            $byDimension[$rule->dimension->value][] = $rule->value;
        }

        return $byDimension;
    }

    /**
     * @param array<string, list<string>> $byDimension
     * @param list<string> $reasons
     */
    private function checkDimension(array $byDimension, array &$reasons, VoucherEligibilityDimension $dimension, ?string $value, string $reasonCode): void
    {
        $values = $byDimension[$dimension->value] ?? [];
        if ($values === []) {
            return;
        }
        if ($value === null || !\in_array($value, $values, true)) {
            $reasons[] = $reasonCode;
        }
    }
}
