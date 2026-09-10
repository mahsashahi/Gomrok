<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Domain\ProviderAccount;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;

final class InMemoryProviderAccountRepository implements ProviderAccountRepository
{
    /** @var array<int, ProviderAccount> */
    private array $byId = [];

    private int $nextId = 1;
    private int $nextEndpointId = 1;

    public function save(ProviderAccount $account): void
    {
        if ($account->id() === null) {
            $account->assignId($this->nextId++);
        }

        foreach ($account->endpoints() as $endpoint) {
            if ($endpoint->id() === null) {
                $endpoint->assignId($this->nextEndpointId++);
            }
        }

        $id = $account->id();
        \assert($id !== null);
        $this->byId[$id] = $account;
    }

    public function findById(int $id): ?ProviderAccount
    {
        return $this->byId[$id] ?? null;
    }

    public function findByClientAndSlug(int $clientId, string $slug): ?ProviderAccount
    {
        foreach ($this->byId as $account) {
            if ($account->clientId() === $clientId && (string) $account->slug() === $slug) {
                return $account;
            }
        }

        return null;
    }

    public function existsForClientWithSlug(int $clientId, string $slug): bool
    {
        return $this->findByClientAndSlug($clientId, $slug) !== null;
    }

    public function findByEndpointToken(string $token): ?ProviderAccount
    {
        foreach ($this->byId as $account) {
            foreach ($account->endpoints() as $endpoint) {
                if ($endpoint->isActive() && $endpoint->token() === $token) {
                    return $account;
                }
            }
        }

        return null;
    }
}
