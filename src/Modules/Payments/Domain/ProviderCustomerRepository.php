<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * Persistence port for {@see ProviderCustomer}.
 */
interface ProviderCustomerRepository
{
    public function save(ProviderCustomer $customer): void;

    public function findByProviderCustomerId(int $providerAccountId, string $providerCustomerId): ?ProviderCustomer;

    public function find(int $providerAccountId, string $clientUserRef): ?ProviderCustomer;
}
