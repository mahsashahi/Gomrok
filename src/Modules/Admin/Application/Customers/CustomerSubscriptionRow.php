<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Customers;

final readonly class CustomerSubscriptionRow
{
    public function __construct(
        public int $subscriptionId,
        public string $packageName,
        public string $price,
        public string $providerAccountName,
        public string $status,
        public ?string $renewLabel,
    ) {
    }
}
