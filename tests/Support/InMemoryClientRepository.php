<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientRepository;

/**
 * In-memory {@see ClientRepository}. Assigns sequential ids and keeps the same
 * object instances, so mutations made through a handler are visible on re-read.
 */
final class InMemoryClientRepository implements ClientRepository
{
    /** @var array<int, Client> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(Client $client): void
    {
        if ($client->id() === null) {
            $client->assignId($this->nextId++);
        }

        $id = $client->id();
        \assert($id !== null);
        $this->byId[$id] = $client;
    }

    public function findById(int $id): ?Client
    {
        return $this->byId[$id] ?? null;
    }

    public function findBySlug(string $slug): ?Client
    {
        foreach ($this->byId as $client) {
            if ((string) $client->slug() === $slug) {
                return $client;
            }
        }

        return null;
    }

    public function existsWithSlug(string $slug): bool
    {
        return $this->findBySlug($slug) !== null;
    }
}
