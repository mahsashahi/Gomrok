<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

final readonly class ProviderSubscriptionResult
{
    public function __construct(
        public string $providerReference,
        public string $redirectUrl,
        public string $rawStatus,
    ) {
    }
}
