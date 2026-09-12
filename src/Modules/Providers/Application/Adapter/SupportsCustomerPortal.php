<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * Optional (Phase 1 Q5) — a self-service portal for the customer to manage
 * their own subscription/payment methods (Stripe's Billing Portal).
 */
interface SupportsCustomerPortal
{
    /**
     * @throws ProviderAdapterException
     */
    public function createBillingPortalSession(string $providerCustomerId, string $returnUrl): ProviderBillingPortalSession;
}
