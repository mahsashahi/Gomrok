<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers;

/**
 * One redemption in the voucher's usage history (CLAUDE.md: "Viewing voucher
 * usage and redemption history"). Carries both the nominal and the applied
 * discount, since `.claude/Voucher.md` §4 deliberately preserves both so a cap
 * or price-floor clamp stays visible rather than silently invisible.
 */
final readonly class RedemptionRow
{
    public function __construct(
        public int $id,
        public ?string $clientUserRef,
        public string $attemptReference,
        public string $status,
        public string $priceLabel,
        public string $nominalDiscountLabel,
        public string $appliedDiscountLabel,
        public string $payableLabel,
        public bool $wasClamped,
        public string $reservedAt,
        public ?string $confirmedAt,
        public ?string $releasedAt,
    ) {
    }
}
