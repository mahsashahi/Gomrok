<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * The result of starting a hosted payment flow — a reference to give back to
 * the provider on future calls, and the URL to redirect the customer to.
 */
final readonly class ProviderPaymentResult
{
    public function __construct(
        public string $providerReference,
        public string $redirectUrl,
        public string $rawStatus,
    ) {
    }
}
