<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application;

use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Subscriptions\Domain\Subscription;

/**
 * Resolves the adapter/capabilities/reference a subscription action (cancel,
 * Phase 26) needs — mirrors
 * {@see \Gomrok\Modules\Payments\Application\ResolvePaymentActionContext},
 * but simpler: a {@see Subscription} already carries its own
 * `providerAccountId` directly, so there's no routing-snapshot lookup.
 *
 * Reference selection prefers the real provider Subscription-resource
 * reference ({@see GatewayReferenceType::Subscription}, recorded by
 * `CreateSubscriptionHandler` when the provider returned one — Stripe's
 * `mode: subscription` checkout session), falling back to the original
 * {@see GatewayReferenceType::CheckoutSession} reference when no deeper one
 * exists (Mollie's provisional subscription support, Phase 26 Q3 — the same
 * checkout-session id serves every action since there's no real Subscription
 * resource yet).
 */
final readonly class ResolveSubscriptionActionContext
{
    public function __construct(
        private ProviderAccountDirectory $accounts,
        private ProviderCapabilityResolver $capabilityResolver,
        private ProviderAdapterFactory $adapterFactory,
        private GatewayReferenceRepository $gatewayReferences,
    ) {
    }

    public function forSubscription(Subscription $subscription): ?SubscriptionActionContext
    {
        $account = $this->accounts->findById($subscription->providerAccountId());
        if ($account === null) {
            return null;
        }

        $capabilities = $this->capabilityResolver->resolve($account->providerTypeCode, $subscription->paymentMethod());
        if ($capabilities === null) {
            return null;
        }

        try {
            $adapter = $this->adapterFactory->for($subscription->providerAccountId());
        } catch (UnsupportedProviderType) {
            return null;
        }

        $subscriptionId = $subscription->id();
        if ($subscriptionId === null) {
            return null;
        }

        $reference = $this->resolveReference($subscriptionId, $subscription->checkoutAttemptId());
        if ($reference === null) {
            return null;
        }

        return new SubscriptionActionContext($subscription->providerAccountId(), $adapter, $capabilities, $reference);
    }

    private function resolveReference(int $subscriptionId, int $checkoutAttemptId): ?string
    {
        $deep = null;
        foreach ($this->gatewayReferences->forSubscription($subscriptionId) as $reference) {
            if ($reference->referenceType === GatewayReferenceType::Subscription) {
                $deep = $reference;
            }
        }
        if ($deep !== null) {
            return $deep->referenceValue;
        }

        // No real provider Subscription resource reference exists yet
        // (Mollie's provisional subscription support, Phase 26 Q3) — fall
        // back to the checkout-session reference recorded when the
        // subscription's first provider checkout was created.
        $primary = null;
        foreach ($this->gatewayReferences->forCheckoutAttempt($checkoutAttemptId) as $reference) {
            if ($reference->referenceType === GatewayReferenceType::CheckoutSession) {
                $primary = $reference;
            }
        }

        return $primary?->referenceValue;
    }
}
