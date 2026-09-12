<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Payments\Domain\Payment}.
 */
final readonly class PaymentSummary
{
    public function __construct(
        public int $id,
        public int $clientId,
        public int $checkoutAttemptId,
        public ?string $clientUserRef,
        public int $packageId,
        public string $country,
        public string $currencyCode,
        public int $amountMinor,
        public string $purchaseType,
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
