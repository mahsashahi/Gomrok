<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Customers;

final readonly class CustomerRow
{
    /**
     * @param list<CustomerProviderRef>     $providerRefs
     * @param list<CustomerSubscriptionRow> $subscriptions
     */
    public function __construct(
        public string $clientUserRef,
        public string $joined,
        public int $subscriptionsCount,
        public int $activeSubscriptionsCount,
        public string $lifetimeSpend,
        public string $status,
        public array $providerRefs,
        public array $subscriptions,
    ) {
    }
}
