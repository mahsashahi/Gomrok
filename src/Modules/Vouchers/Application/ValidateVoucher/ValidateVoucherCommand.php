<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ValidateVoucher;

/**
 * A non-locking pre-check (Phase 19 Q3): resolves the package's price the same
 * way `pricing/resolve` does, then runs the Phase 16 eligibility evaluator and,
 * if eligible, the Phase 17 discount calculator — as a preview only. Nothing is
 * reserved, redeemed, or written. Backs `GET /api/v1/vouchers/validate`.
 */
final readonly class ValidateVoucherCommand
{
    public function __construct(
        public int $clientId,
        public string $packageCode,
        public string $country,
        public string $voucherCode,
        public ?string $deviceType = null,
        public ?string $paymentMethod = null,
        public ?string $purchaseType = null,
        public ?string $subscriptionInterval = null,
        public ?string $clientUserRef = null,
        public ?bool $isFirstPurchase = null,
    ) {
    }
}
