<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Checkout\Domain\CheckoutAttempt}.
 */
final readonly class CheckoutAttemptSummary
{
    public function __construct(
        public int $id,
        public int $clientId,
        public ?string $clientUserRef,
        public string $attemptReference,
        public int $packageId,
        public string $country,
        public string $currencyCode,
        public ?string $purchaseType,
        public ?string $paymentMethod,
        public ?string $subscriptionInterval,
        public string $status,
        public ?string $errorCode,
        public ?string $errorMessage,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
