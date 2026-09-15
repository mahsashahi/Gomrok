<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Application\SubscriptionSummary;
use Gomrok\Modules\Subscriptions\Domain\Subscription;

/**
 * A {@see SubscriptionDirectory} projected straight off an
 * {@see InMemorySubscriptionRepository} — mirrors {@see InMemoryPaymentDirectory}.
 */
final readonly class InMemorySubscriptionDirectory implements SubscriptionDirectory
{
    public function __construct(private InMemorySubscriptionRepository $subscriptions)
    {
    }

    public function findById(int $id): ?SubscriptionSummary
    {
        $subscription = $this->subscriptions->findById($id);

        return $subscription !== null ? $this->toSummary($subscription) : null;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?SubscriptionSummary
    {
        $subscription = $this->subscriptions->findByCheckoutAttemptId($checkoutAttemptId);

        return $subscription !== null ? $this->toSummary($subscription) : null;
    }

    public function forClientUser(int $clientId, string $clientUserRef): array
    {
        return array_map($this->toSummary(...), $this->subscriptions->forClientUser($clientId, $clientUserRef));
    }

    private function toSummary(Subscription $subscription): SubscriptionSummary
    {
        return new SubscriptionSummary(
            $subscription->id() ?? 0,
            $subscription->clientId(),
            $subscription->clientUserRef(),
            $subscription->checkoutAttemptId(),
            $subscription->packageId(),
            $subscription->providerAccountId(),
            $subscription->currencyCode(),
            $subscription->amountMinor(),
            $subscription->paymentMethod()?->value,
            $subscription->interval()->value,
            $subscription->status()->value,
            $subscription->trialEndsAt()?->format('Y-m-d H:i:s'),
            $subscription->currentPeriodStart()?->format('Y-m-d H:i:s'),
            $subscription->currentPeriodEnd()?->format('Y-m-d H:i:s'),
            $subscription->errorCode(),
            $subscription->errorMessage(),
            $subscription->createdAt()->format('Y-m-d H:i:s'),
            $subscription->updatedAt()?->format('Y-m-d H:i:s'),
        );
    }
}
