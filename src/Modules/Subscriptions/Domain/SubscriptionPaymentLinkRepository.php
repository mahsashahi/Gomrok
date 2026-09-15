<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain;

interface SubscriptionPaymentLinkRepository
{
    /**
     * @return int the new row's id
     */
    public function save(SubscriptionPaymentLink $link): int;

    /**
     * @return list<SubscriptionPaymentLink>
     */
    public function forSubscription(int $subscriptionId): array;

    public function findByPaymentId(int $paymentId): ?SubscriptionPaymentLink;
}
