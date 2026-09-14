<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application;

use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherDecisionSnapshotRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemption;
use Gomrok\Modules\Vouchers\Domain\VoucherRedemptionRepository;

/**
 * Resolves {@see CheckoutPayableAmount} for a checkout attempt — extracted
 * from `CreatePaymentHandler` (Phase 20) so the same "voucher's payable
 * amount wins over the plain pricing snapshot when a voucher was reserved"
 * rule can't drift between it and `CreateProviderCheckoutHandler` (Phase 24).
 */
final readonly class ResolveCheckoutPayableAmount
{
    public function __construct(
        private PricingDecisionSnapshotRepository $pricingSnapshots,
        private VoucherDecisionSnapshotRepository $voucherSnapshots,
        private VoucherRedemptionRepository $redemptions,
    ) {
    }

    /**
     * `null` when the checkout attempt has no resolved pricing snapshot yet.
     */
    public function forCheckoutAttempt(int $checkoutAttemptId): ?CheckoutPayableAmount
    {
        $pricing = $this->pricingSnapshots->findByCheckoutAttemptId($checkoutAttemptId);
        if ($pricing === null) {
            return null;
        }

        $amountMinor = $pricing->amountMinor;

        $voucherSnapshot = $this->voucherSnapshots->findByCheckoutAttemptId($checkoutAttemptId);
        if ($voucherSnapshot !== null) {
            $redemption = $this->redemptions->findById($voucherSnapshot->voucherRedemptionId);
            \assert($redemption instanceof VoucherRedemption);
            $amountMinor = $redemption->payableMinor();
        }

        return new CheckoutPayableAmount($amountMinor, $pricing->currencyCode);
    }
}
