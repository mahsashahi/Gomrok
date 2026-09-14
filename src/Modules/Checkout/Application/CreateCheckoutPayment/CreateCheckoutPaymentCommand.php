<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutPayment;

/**
 * The end-to-end "create a payment" request (Phase 24) — the client never
 * drives the individual checkout-attempt steps (start/resolve-pricing/
 * reserve-voucher/select-provider/create-provider-checkout) itself; this one
 * command composes all of them.
 */
final readonly class CreateCheckoutPaymentCommand
{
    public function __construct(
        public int $clientId,
        public string $mode,
        public string $attemptReference,
        public int $packageId,
        public string $country,
        public string $currencyCode,
        public ?string $clientUserRef = null,
        public ?string $purchaseType = null,
        public ?string $paymentMethod = null,
        public ?string $subscriptionInterval = null,
        public ?string $deviceType = null,
        public ?string $voucherCode = null,
        public ?bool $isFirstPurchase = null,
        public ?string $customerEmail = null,
        public ?int $actorId = null,
    ) {
    }
}
