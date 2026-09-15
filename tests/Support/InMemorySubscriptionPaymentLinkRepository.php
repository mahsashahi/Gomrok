<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLink;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLinkRepository;

final class InMemorySubscriptionPaymentLinkRepository implements SubscriptionPaymentLinkRepository
{
    /** @var array<int, SubscriptionPaymentLink> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(SubscriptionPaymentLink $link): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = $link;

        return $id;
    }

    public function forSubscription(int $subscriptionId): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (SubscriptionPaymentLink $l): bool => $l->subscriptionId === $subscriptionId,
        ));
    }

    public function findByPaymentId(int $paymentId): ?SubscriptionPaymentLink
    {
        foreach ($this->byId as $link) {
            if ($link->paymentId === $paymentId) {
                return $link;
            }
        }

        return null;
    }
}
