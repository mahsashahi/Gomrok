<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Payments\Domain\ProviderCustomer;
use Gomrok\Modules\Payments\Domain\ProviderCustomerRepository;

final class InMemoryProviderCustomerRepository implements ProviderCustomerRepository
{
    /** @var array<int, ProviderCustomer> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(ProviderCustomer $customer): void
    {
        if ($customer->id() === null) {
            $customer->assignId($this->nextId++);
        }
        $id = $customer->id();
        \assert($id !== null);
        $this->byId[$id] = $customer;
    }

    public function findByProviderCustomerId(int $providerAccountId, string $providerCustomerId): ?ProviderCustomer
    {
        foreach ($this->byId as $customer) {
            if ($customer->providerAccountId() === $providerAccountId && $customer->providerCustomerId() === $providerCustomerId) {
                return $customer;
            }
        }

        return null;
    }

    public function find(int $providerAccountId, string $clientUserRef): ?ProviderCustomer
    {
        foreach ($this->byId as $customer) {
            if ($customer->providerAccountId() === $providerAccountId && $customer->clientUserRef() === $clientUserRef) {
                return $customer;
            }
        }

        return null;
    }
}
