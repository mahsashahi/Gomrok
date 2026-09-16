<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers;

use Gomrok\Modules\Vouchers\Application\VoucherDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherRedemptionDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherRedemptionSummary;
use Gomrok\Modules\Vouchers\Application\VoucherSummary;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityDimension;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * Builds the Vouchers screen (Phase 27) — a master-detail over
 * {@see VoucherDirectory}, with each voucher's discount configuration,
 * eligibility rules, usage caps and redemption history. Discount labels follow
 * `.claude/Voucher.md` §4's resolution order rather than re-deriving it, so
 * what the admin reads here matches what a checkout would actually apply.
 */
final readonly class VouchersScreenHandler
{
    public function __construct(
        private VoucherDirectory $vouchers,
        private VoucherRedemptionDirectory $redemptions,
    ) {
    }

    public function forClient(int $clientId, ?string $selectedCode): VouchersScreenResult
    {
        $vouchers = $this->vouchers->forClient($clientId);

        $selected = null;
        foreach ($vouchers as $voucher) {
            if ($voucher->code === $selectedCode) {
                $selected = $voucher;

                break;
            }
        }
        $selected ??= $vouchers[0] ?? null;

        $items = array_map(
            fn (VoucherSummary $v): VoucherListItem => new VoucherListItem(
                $v->code,
                $v->name,
                $v->status,
                $this->defaultDiscountLabel($v),
                $this->usageLabel($v),
                $selected !== null && $v->code === $selected->code,
            ),
            $vouchers,
        );

        return new VouchersScreenResult($items, $selected !== null ? $this->buildDetail($selected) : null);
    }

    private function buildDetail(VoucherSummary $voucher): VoucherDetail
    {
        return new VoucherDetail(
            $voucher->id,
            $voucher->code,
            $voucher->name,
            $voucher->description,
            $voucher->status,
            $voucher->status === 'active',
            $voucher->validFrom,
            $voucher->validUntil,
            $this->validityLabel($voucher),
            $voucher->firstPurchaseOnly,
            $voucher->minPurchaseMinor,
            $voucher->minPurchaseCurrency,
            $this->minPurchaseLabel($voucher),
            $voucher->defaultDiscountType,
            $voucher->defaultPercentBp,
            $this->defaultDiscountLabel($voucher),
            $voucher->maxTotalRedemptions,
            $voucher->maxPerUser,
            $voucher->maxPerClient,
            $voucher->redeemedCount,
            $this->usageLabel($voucher),
            $this->currencyDiscountRows($voucher),
            $this->eligibilityGroups($voucher),
            $this->eligibilityPrefill($voucher),
            $this->redemptionRows($voucher->id),
        );
    }

    /**
     * Every dimension mapped to its comma-joined values (empty string when
     * unrestricted) — the "Edit eligibility" modal has one field per dimension
     * and needs a value for each, present or not.
     *
     * @return array<string, string>
     */
    private function eligibilityPrefill(VoucherSummary $voucher): array
    {
        $byDimension = [];
        foreach ($voucher->eligibilityRules as $rule) {
            $byDimension[$rule['dimension']][] = $rule['value'];
        }

        $prefill = [];
        foreach (VoucherEligibilityDimension::cases() as $dimension) {
            $prefill[$dimension->value] = implode(', ', $byDimension[$dimension->value] ?? []);
        }

        return $prefill;
    }

    /**
     * @return list<CurrencyDiscountRow>
     */
    private function currencyDiscountRows(VoucherSummary $voucher): array
    {
        $rows = [];
        foreach ($voucher->currencyDiscounts as $discount) {
            $currency = $discount['currency'];
            $type = $discount['discount_type'];
            $percentBp = $discount['percent_bp'];
            $amountMinor = $discount['amount_minor'];
            $maxDiscountMinor = $discount['max_discount_minor'];

            $label = match ($type) {
                'fixed' => $amountMinor !== null ? $this->money($amountMinor, $currency) : '—',
                'percentage' => $percentBp !== null ? $this->percent($percentBp) : '—',
                'full' => '100% (full)',
                default => $type,
            };

            $rows[] = new CurrencyDiscountRow(
                $currency,
                $type,
                $label,
                $maxDiscountMinor !== null ? 'max ' . $this->money($maxDiscountMinor, $currency) : null,
                $percentBp,
                $amountMinor,
                $maxDiscountMinor,
            );
        }

        return $rows;
    }

    /**
     * @return list<EligibilityRuleGroup>
     */
    private function eligibilityGroups(VoucherSummary $voucher): array
    {
        $byDimension = [];
        foreach ($voucher->eligibilityRules as $rule) {
            $byDimension[$rule['dimension']][] = $rule['value'];
        }

        $groups = [];
        foreach (VoucherEligibilityDimension::cases() as $dimension) {
            $values = $byDimension[$dimension->value] ?? [];
            if ($values !== []) {
                $groups[] = new EligibilityRuleGroup($dimension->value, self::label($dimension->value), $values);
            }
        }

        return $groups;
    }

    /**
     * @return list<RedemptionRow>
     */
    private function redemptionRows(int $voucherId): array
    {
        return array_map(
            fn (VoucherRedemptionSummary $r): RedemptionRow => new RedemptionRow(
                $r->id,
                $r->clientUserRef,
                $r->attemptReference,
                $r->status,
                $this->money($r->priceMinor, $r->currencyCode),
                $this->money($r->nominalDiscountMinor, $r->currencyCode),
                $this->money($r->appliedDiscountMinor, $r->currencyCode),
                $this->money($r->payableMinor, $r->currencyCode),
                $r->appliedDiscountMinor < $r->nominalDiscountMinor,
                $r->reservedAt,
                $r->confirmedAt,
                $r->releasedAt,
            ),
            $this->redemptions->forVoucher($voucherId),
        );
    }

    private function defaultDiscountLabel(VoucherSummary $voucher): string
    {
        return match ($voucher->defaultDiscountType) {
            'percentage' => $voucher->defaultPercentBp !== null ? $this->percent($voucher->defaultPercentBp) : '—',
            'full' => '100% (full)',
            'none' => $voucher->currencyDiscounts === [] ? 'No discount configured' : 'Per-currency only',
            default => $voucher->defaultDiscountType,
        };
    }

    private function usageLabel(VoucherSummary $voucher): string
    {
        $parts = [];
        $parts[] = $voucher->maxTotalRedemptions !== null
            ? "{$voucher->redeemedCount} / {$voucher->maxTotalRedemptions} used"
            : "{$voucher->redeemedCount} used";
        if ($voucher->maxPerUser !== null) {
            $parts[] = $voucher->maxPerUser === 1 ? 'once per user' : "max {$voucher->maxPerUser} per user";
        }

        return implode(' · ', $parts);
    }

    private function validityLabel(VoucherSummary $voucher): string
    {
        if ($voucher->validFrom === null && $voucher->validUntil === null) {
            return 'Always valid';
        }
        $from = $voucher->validFrom !== null ? substr($voucher->validFrom, 0, 10) : '—';
        $until = $voucher->validUntil !== null ? substr($voucher->validUntil, 0, 10) : '—';

        return "{$from} → {$until}";
    }

    private function minPurchaseLabel(VoucherSummary $voucher): ?string
    {
        if ($voucher->minPurchaseMinor === null || $voucher->minPurchaseCurrency === null) {
            return null;
        }

        return $this->money($voucher->minPurchaseMinor, $voucher->minPurchaseCurrency);
    }

    private function money(int $minor, string $currencyCode): string
    {
        try {
            return Money::fromMinor($minor, Currency::of($currencyCode))->format('en_US');
        } catch (InvalidArgumentException) {
            return $minor . ' ' . $currencyCode;
        }
    }

    /** `percent_bp` is basis points — 1000 = 10.00% (`.claude/Voucher.md` §4). */
    private function percent(int $percentBp): string
    {
        return rtrim(rtrim(number_format($percentBp / 100, 2, '.', ''), '0'), '.') . '%';
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
