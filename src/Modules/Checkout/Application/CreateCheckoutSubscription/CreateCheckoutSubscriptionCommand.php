<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutSubscription;

/**
 * The end-to-end "create a subscription" request (Phase 26) — mirrors
 * `CreateCheckoutPaymentCommand`, but `clientUserRef` and `subscriptionInterval`
 * are both mandatory here (Q4: CLAUDE.md requires a known owner for every
 * subscription; a subscription is meaningless without a billing interval).
 */
final readonly class CreateCheckoutSubscriptionCommand
{
    public function __construct(
        public int $clientId,
        public string $mode,
        public string $attemptReference,
        public int $packageId,
        public string $country,
        public string $currencyCode,
        public string $clientUserRef,
        public string $subscriptionInterval,
        public ?string $paymentMethod = null,
        public ?string $deviceType = null,
        public ?string $voucherCode = null,
        public ?bool $isFirstPurchase = null,
        public ?string $customerEmail = null,
        public ?int $actorId = null,
    ) {
    }
}
