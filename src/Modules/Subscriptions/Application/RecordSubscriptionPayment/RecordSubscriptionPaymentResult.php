<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\RecordSubscriptionPayment;

final readonly class RecordSubscriptionPaymentResult
{
    public function __construct(
        public int $paymentId,
        public string $paymentStatus,
        public string $subscriptionStatus,
        public bool $alreadyRecorded,
    ) {
    }
}
