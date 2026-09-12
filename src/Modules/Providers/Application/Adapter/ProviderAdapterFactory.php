<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * Resolves a `provider_account_id` to a fully-credentialed adapter instance
 * (Phase 21 Q4) — the one place that knows "provider type code X gets adapter
 * class Y," growing one `match` arm per provider type as Phases 22–23 add
 * Mollie/PayPal/Ziraat. Callers that need an optional capability check with
 * `instanceof` (e.g. `$adapter instanceof SupportsRefunds`).
 */
interface ProviderAdapterFactory
{
    /**
     * @throws UnsupportedProviderType   when the account's provider type has no adapter yet
     * @throws \RuntimeException         when the account itself doesn't exist
     */
    public function for(int $providerAccountId): PaymentProviderPort;
}
