<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use InvalidArgumentException;

/**
 * Parses a human-entered decimal amount from an admin form into minor units.
 * Returns `[minorUnits, errorMessage]` — a blank input is `[null, null]` (the
 * field is simply unset), an unparseable one is `[null, "…"]`.
 */
final class AdminMoneyInput
{
    /**
     * @return array{0: ?int, 1: ?string}
     */
    public static function parse(string $amount, string $currencyCode): array
    {
        $amount = trim($amount);
        if ($amount === '') {
            return [null, null];
        }

        try {
            $currency = Currency::of($currencyCode);
        } catch (InvalidArgumentException) {
            return [null, "'{$currencyCode}' is not a valid currency."];
        }

        $money = Money::fromDecimalInput($amount, $currency);
        if ($money === null) {
            return [null, "'{$amount}' is not a valid amount for {$currency->code()}."];
        }

        return [$money->toMinor(), null];
    }

    /**
     * A percentage as typed ("10", "12.5") into basis points — 10 → 1000
     * (`.claude/Voucher.md` §4: `percent_bp` 500 = 5.00%). Blank → null.
     */
    public static function percentToBasisPoints(string $percent): ?int
    {
        $percent = trim($percent);
        if ($percent === '' || preg_match('/^\d+(\.\d+)?$/', $percent) !== 1) {
            return null;
        }

        return (int) round(((float) $percent) * 100);
    }

    private function __construct()
    {
    }
}
