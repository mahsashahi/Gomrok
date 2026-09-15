<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment;

use DateTimeImmutable;

final readonly class RecordSubscriptionPaymentCommand
{
    public function __construct(
        public int $clientId,
        public int $subscriptionId,
        public string $providerPaymentReference,
        public string $rawStatus,
        public string $mappedStatus,
        public ?DateTimeImmutable $billingPeriodStart = null,
        public ?DateTimeImmutable $billingPeriodEnd = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $actorId = null,
    ) {
    }
}
