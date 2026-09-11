<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption;

/**
 * Re-checks eligibility, computes the discount, and reserves usage against a
 * voucher's caps (Phase 17). Idempotent by `(voucherId, attemptReference)` —
 * Phase 20 will pass the payment id as `attemptReference`.
 */
final readonly class ReserveVoucherRedemptionCommand
{
    public function __construct(
        public int $clientId,
        public int $voucherId,
        public string $attemptReference,
        public string $currencyCode,
        public int $priceMinor,
        public ?string $country = null,
        public ?int $packageId = null,
        public ?int $providerAccountId = null,
        public ?string $paymentMethod = null,
        public ?string $purchaseType = null,
        public ?string $subscriptionInterval = null,
        public ?string $clientUserRef = null,
        public ?bool $isFirstPurchase = null,
        public ?int $actorId = null,
    ) {
    }
}
