<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * The checkout context a voucher is evaluated against (Phase 16). `amountMinor`
 * / `amountCurrency` is the pre-discount price — the minimum-purchase check
 * only fires when `amountCurrency` equals the voucher's `min_purchase_currency`
 * (no FX conversion in this comparison). `isFirstPurchase` is supplied by the
 * caller — Gomrok has no purchase-history lookup in Phase 16.
 */
final readonly class VoucherContext
{
    public function __construct(
        public int $clientId,
        public DateTimeImmutable $now,
        public ?string $country = null,
        public ?string $currency = null,
        public ?int $packageId = null,
        public ?int $providerAccountId = null,
        public ?PaymentMethod $paymentMethod = null,
        public ?PurchaseType $purchaseType = null,
        public ?SubscriptionInterval $subscriptionInterval = null,
        public ?int $amountMinor = null,
        public ?string $amountCurrency = null,
        public ?string $clientUserRef = null,
        public ?bool $isFirstPurchase = null,
    ) {
    }
}
