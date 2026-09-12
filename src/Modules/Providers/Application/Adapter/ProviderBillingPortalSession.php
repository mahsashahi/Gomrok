<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

final readonly class ProviderBillingPortalSession
{
    public function __construct(
        public string $redirectUrl,
    ) {
    }
}
