<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscount;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRule;

/**
 * `before` / `after` payloads for `voucher_*` audit rows. No secrets.
 */
final class VoucherAuditSnapshot
{
    /**
     * @return array<string, scalar|null>
     */
    public static function voucher(Voucher $voucher): array
    {
        return [
            'id' => $voucher->id(),
            'client_id' => $voucher->clientId(),
            'code' => $voucher->code(),
            'name' => $voucher->name(),
            'description' => $voucher->description(),
            'status' => $voucher->status()->value,
            'valid_from' => $voucher->validFrom()?->format('Y-m-d H:i:s'),
            'valid_until' => $voucher->validUntil()?->format('Y-m-d H:i:s'),
            'first_purchase_only' => $voucher->firstPurchaseOnly(),
            'min_purchase_minor' => $voucher->minPurchaseMinor(),
            'min_purchase_currency' => $voucher->minPurchaseCurrency(),
            'default_discount_type' => $voucher->defaultDiscountType()->value,
            'default_percent_bp' => $voucher->defaultPercentBp(),
            'max_total_redemptions' => $voucher->maxTotalRedemptions(),
            'max_per_user' => $voucher->maxPerUser(),
            'max_per_client' => $voucher->maxPerClient(),
        ];
    }

    /**
     * @param list<VoucherEligibilityRule> $rules
     *
     * @return list<array{dimension: string, value: string}>
     */
    public static function eligibilityRules(array $rules): array
    {
        return array_map(
            static fn (VoucherEligibilityRule $r): array => ['dimension' => $r->dimension->value, 'value' => $r->value],
            $rules,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function currencyDiscount(VoucherCurrencyDiscount $discount): array
    {
        return [
            'voucher_id' => $discount->voucherId,
            'currency' => $discount->currencyCode,
            'discount_type' => $discount->discountType->value,
            'percent_bp' => $discount->percentBp,
            'amount_minor' => $discount->amountMinor,
            'max_discount_minor' => $discount->maxDiscountMinor,
        ];
    }
}
