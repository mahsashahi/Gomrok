<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\LinkProviderCustomer;

final readonly class LinkProviderCustomerResult
{
    public function __construct(
        public int $providerCustomerRowId,
    ) {
    }
}
