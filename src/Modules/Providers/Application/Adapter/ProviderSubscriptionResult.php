<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

final readonly class ProviderSubscriptionResult
{
    public function __construct(
        public string $providerReference,
        public string $redirectUrl,
        public string $rawStatus,
        /**
         * The provider's customer id, when the adapter creates one as part
         * of this call (Mollie, Phase 29 Q2 — needed later to activate the
         * real Subscription resource once the mandate is confirmed). Null
         * for an adapter with no such intermediate concept.
         */
        public ?string $customerId = null,
    ) {
    }
}
