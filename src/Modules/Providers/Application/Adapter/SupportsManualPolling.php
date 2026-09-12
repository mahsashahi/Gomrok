<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * Optional (Phase 1 Q5) — for a provider with no reliable webhook (e.g.
 * Ziraat, Phase 23), so Gomrok must actively poll for the outcome instead of
 * waiting for a callback.
 */
interface SupportsManualPolling
{
    /**
     * @throws ProviderAdapterException
     */
    public function pollPaymentStatus(string $providerReference): ProviderPaymentStatus;
}
