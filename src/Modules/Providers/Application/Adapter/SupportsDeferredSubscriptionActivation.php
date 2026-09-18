<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * Optional (Phase 29 Q2) — implemented only by a provider whose
 * `createSubscription()` can't produce a real, recurring subscription
 * resource in one call. Mollie implements this: a customer must first
 * authorize recurring charges via a one-off first payment before Mollie's
 * own Subscriptions API can be called; `StripeAdapter` never needs this —
 * Stripe Checkout creates the real subscription immediately.
 */
interface SupportsDeferredSubscriptionActivation
{
    /**
     * @throws ProviderAdapterException
     */
    public function activateSubscription(string $customerId, ActivateSubscriptionCommand $command): ProviderSubscriptionResult;
}
