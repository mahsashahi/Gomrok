<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\DiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherCurrencyDiscountRepository;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Money;
use Gomrok\Shared\Domain\Result;

/**
 * Resolves a voucher's applicable discount for a checkout currency (Phase 16's
 * override-then-default) against a real price, and produces the final payable
 * amount (Phase 17 Q4): a per-currency override, if one exists, wins; otherwise
 * the voucher's default (`percentage` / `full`); `none` with no override is not
 * applicable in that currency (the caller should have already rejected this via
 * {@see VoucherEligibilityEvaluator}, but the calculator checks defensively).
 *
 * The result is always clamped to `[0, price]` — a discount can never make the
 * payable amount negative — while `nominalDiscountMinor` preserves what the
 * rule itself would have produced, for later audit/snapshot use.
 */
final readonly class VoucherDiscountCalculator
{
    public function __construct(private VoucherCurrencyDiscountRepository $overrides)
    {
    }

    /**
     * @return Result ok({@see VoucherDiscountResult}) | err({@see DomainError})
     */
    public function calculate(Voucher $voucher, string $currencyCode, int $priceMinor): Result
    {
        $currencyCode = strtoupper($currencyCode);
        $voucherId = $voucher->id();
        $override = $voucherId !== null ? $this->overrides->find($voucherId, $currencyCode) : null;

        if ($override !== null) {
            $type = $override->discountType;
            $percentBp = $override->percentBp;
            $amountMinor = $override->amountMinor;
            $maxDiscountMinor = $override->maxDiscountMinor;
        } else {
            $type = match ($voucher->defaultDiscountType()) {
                DefaultDiscountType::Percentage => DiscountType::Percentage,
                DefaultDiscountType::Full => DiscountType::Full,
                DefaultDiscountType::None => null,
            };
            $percentBp = $voucher->defaultPercentBp();
            $amountMinor = null;
            $maxDiscountMinor = null;
        }

        if ($type === null) {
            return Result::err(DomainError::unsupported(
                'voucher.no_discount_for_currency',
                "This voucher has no applicable discount for '{$currencyCode}'.",
                ['currency' => $currencyCode],
            ));
        }

        $currency = Currency::of($currencyCode);
        $price = Money::fromMinor($priceMinor, $currency);

        // "Nominal" is what the rule itself says, before any clamping — the
        // merchant-configured cap and the hard price-floor are both forms of
        // clamping applied afterward, on the way to "applied".
        $nominal = match ($type) {
            DiscountType::Fixed => Money::fromMinor($amountMinor ?? 0, $currency),
            DiscountType::Percentage => $price->percentage(self::basisPointsToPercent($percentBp ?? 0)),
            DiscountType::Full => $price,
        };

        $applied = $nominal;
        if ($type === DiscountType::Percentage && $maxDiscountMinor !== null) {
            $cap = Money::fromMinor($maxDiscountMinor, $currency);
            if ($applied->toMinor() > $cap->toMinor()) {
                $applied = $cap;
            }
        }
        if ($applied->toMinor() > $price->toMinor()) {
            $applied = $price;
        }
        $payable = $price->minus($applied);

        return Result::ok(new VoucherDiscountResult(
            $currencyCode,
            $price->toMinor(),
            $nominal->toMinor(),
            $applied->toMinor(),
            $payable->toMinor(),
        ));
    }

    /**
     * `1000` (10.00%) -> `"10.00"`, exact (no float division).
     */
    private static function basisPointsToPercent(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        return $whole . '.' . str_pad((string) $fraction, 2, '0', STR_PAD_LEFT);
    }
}
