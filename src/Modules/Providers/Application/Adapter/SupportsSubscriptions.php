<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * Optional (Phase 1 Q5) — implemented only by providers that really support
 * subscriptions. `StripeAdapter` implements this; a future `ZiraatAdapter`
 * (payment-only, per CLAUDE.md's Provider Adapter Pattern) does not.
 */
interface SupportsSubscriptions
{
    /**
     * @throws ProviderAdapterException
     */
    public function createSubscription(CreateSubscriptionCommand $command): ProviderSubscriptionResult;

    /**
     * @throws ProviderAdapterException
     */
    public function getSubscriptionStatus(string $providerReference): ProviderSubscriptionStatus;

    /**
     * @throws ProviderAdapterException
     */
    public function cancelSubscription(string $providerReference): void;

    /**
     * Provisional — see {@see ProviderSubscriptionStatus}'s docblock.
     */
    public function mapProviderSubscriptionStatusToInternalStatus(string $providerStatus): string;
}
