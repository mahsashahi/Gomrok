<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt;

/**
 * Starts a checkout attempt. `attemptReference` is the external, caller-supplied
 * idempotent key (Phase 18 Q1) — a repeat call with the same reference returns
 * the existing attempt unchanged.
 */
final readonly class CreateCheckoutAttemptCommand
{
    public function __construct(
        public int $clientId,
        public string $attemptReference,
        public int $packageId,
        public string $country,
        public string $currencyCode,
        public ?string $clientUserRef = null,
        public ?string $purchaseType = null,
        public ?string $paymentMethod = null,
        public ?string $subscriptionInterval = null,
        public ?int $actorId = null,
    ) {
    }
}
