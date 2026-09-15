<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\CancelSubscription;

use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\SupportsSubscriptions;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Subscriptions\Application\ResolveSubscriptionActionContext;
use Gomrok\Modules\Subscriptions\Application\SubscriptionAuditSnapshot;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEvent;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEventRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * `POST /api/v1/subscriptions/{id}/cancel` (Phase 26) — mirrors
 * `CancelPaymentHandler`'s capability-gated shape (Phase 24 Q5b): both
 * `Capability::SubscriptionCancel` must be declared for the provider/method
 * and the adapter must actually implement {@see SupportsSubscriptions}
 * before the provider is called at all.
 */
final readonly class CancelSubscriptionHandler
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
        private SubscriptionEventRepository $subscriptionEvents,
        private ResolveSubscriptionActionContext $context,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CancelSubscriptionCommand $command): Result
    {
        $subscription = $this->subscriptions->findById($command->subscriptionId);
        if ($subscription === null || $subscription->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('subscription.not_found', "Subscription {$command->subscriptionId} was not found for this client."));
        }

        if ($subscription->status() === SubscriptionStatus::Cancelled) {
            return Result::err(DomainError::conflict(
                'subscription.already_cancelled',
                'This subscription is already cancelled.',
                ['status' => $subscription->status()->value],
            ));
        }

        $context = $this->context->forSubscription($subscription);
        if ($context === null) {
            return Result::err(DomainError::validation('subscription.provider_context_unavailable', 'The provider account or gateway reference for this subscription could not be resolved.'));
        }

        if (!$context->capabilities->supports(Capability::SubscriptionCancel) || !$context->adapter instanceof SupportsSubscriptions) {
            return Result::err(DomainError::unsupported('subscription.cancel_not_supported', 'The selected provider does not support cancelling this subscription for this client/country.'));
        }

        try {
            $context->adapter->cancelSubscription($context->providerReference);
        } catch (ProviderAdapterException $e) {
            return Result::err(DomainError::upstreamFailure('subscription.cancel_failed', $e->getMessage()));
        }

        $now = $this->clock->now();
        $before = SubscriptionAuditSnapshot::of($subscription);
        $error = $subscription->transitionTo(SubscriptionStatus::Cancelled, $now);
        if ($error !== null) {
            return Result::err($error);
        }

        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        $this->transactions->run(function () use ($subscription, $before, $subscriptionId, $command, $now): void {
            $this->subscriptions->save($subscription);
            $this->subscriptionEvents->save(SubscriptionEvent::record($subscriptionId, 'cancelled', null, null, $now));

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'subscription.cancelled')
                : AuditEntry::forSystem('subscription.cancelled', $command->clientId);
            $this->audit->record($entry->withTarget('subscription', $subscriptionId)->withChange($before, SubscriptionAuditSnapshot::of($subscription)));
        });

        return Result::ok(new CancelSubscriptionResult($subscriptionId, $subscription->status()->value));
    }
}
