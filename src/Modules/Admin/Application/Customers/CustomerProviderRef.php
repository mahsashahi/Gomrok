<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Customers;

final readonly class CustomerProviderRef
{
    public function __construct(
        public string $providerName,
        public string $providerCustomerId,
    ) {
    }
}
