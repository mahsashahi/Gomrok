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

    /**
     * Every provider identity linked for one client user, across every
     * provider account — the admin panel's Customers screen (Phase 27) needs
     * "all of this customer's provider references," not one account's.
     *
     * @return list<ProviderCustomer>
     */
    public function forClientUser(int $clientId, string $clientUserRef): array;
}
