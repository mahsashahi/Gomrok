<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

use Gomrok\Shared\Domain\DomainError;

/**
 * A per-currency override of a voucher's default discount (Phase 16 Q2). A
 * currency using the default has no row here. Addressed by
 * `(voucherId, currencyCode)` — `UNIQUE (voucher_id, currency_code)`.
 */
final readonly class VoucherCurrencyDiscount
{
    public string $currencyCode;

    public function __construct(
        public int $voucherId,
        string $currencyCode,
        public DiscountType $discountType,
        public ?int $percentBp,
        public ?int $amountMinor,
        public ?int $maxDiscountMinor,
    ) {
        $this->currencyCode = strtoupper($currencyCode);
    }

    /**
     * Structural consistency for a create/update, mirroring the {@see DiscountType}.
     */
    public static function validate(DiscountType $type, ?int $percentBp, ?int $amountMinor, ?int $maxDiscountMinor): ?DomainError
    {
        return match ($type) {
            DiscountType::Fixed => match (true) {
                $amountMinor === null || $amountMinor <= 0 => DomainError::validation('voucher_currency_discount.amount_required', 'A fixed discount needs a positive amount.'),
                $percentBp !== null => DomainError::validation('voucher_currency_discount.unexpected_percent', 'A fixed discount cannot carry a percentage.'),
                $maxDiscountMinor !== null => DomainError::validation('voucher_currency_discount.unexpected_cap', 'A fixed discount cannot carry a cap.'),
                default => null,
            },
            DiscountType::Percentage => match (true) {
                $percentBp === null || $percentBp < 1 || $percentBp > 10000 => DomainError::validation('voucher_currency_discount.percent_out_of_range', 'The percentage must be between 0.01% and 100% (1..10000 basis points).'),
                $amountMinor !== null => DomainError::validation('voucher_currency_discount.unexpected_amount', 'A percentage discount cannot carry a fixed amount.'),
                default => null,
            },
            DiscountType::Full => match (true) {
                $percentBp !== null || $amountMinor !== null || $maxDiscountMinor !== null => DomainError::validation('voucher_currency_discount.unexpected_value', 'A full discount cannot carry a percentage, amount, or cap.'),
                default => null,
            },
        };
    }
}
