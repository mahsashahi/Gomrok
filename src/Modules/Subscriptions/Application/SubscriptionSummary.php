<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application;

/**
 * Read-only view of a {@see \Gomrok\Modules\Subscriptions\Domain\Subscription}.
 */
final readonly class SubscriptionSummary
{
    public function __construct(
        public int $id,
        public int $clientId,
        public string $clientUserRef,
        public int $checkoutAttemptId,
        public int $packageId,
        public int $providerAccountId,
        public string $currencyCode,
        public int $amountMinor,
        public ?string $paymentMethod,
        public string $interval,
        public string $status,
        public ?string $trialEndsAt,
        public ?string $currentPeriodStart,
        public ?string $currentPeriodEnd,
        public ?string $errorCode,
        public ?string $errorMessage,
        public string $createdAt,
        public ?string $updatedAt,
    ) {
    }
}
