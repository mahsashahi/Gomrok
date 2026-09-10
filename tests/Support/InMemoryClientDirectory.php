<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\ClientSnapshot;

final class InMemoryClientDirectory implements ClientDirectory
{
    /** @var array<int, ClientSnapshot> */
    private array $byId = [];

    public function add(ClientSnapshot $snapshot): void
    {
        $this->byId[$snapshot->id] = $snapshot;
    }

    public function findById(int $id): ?ClientSnapshot
    {
        return $this->byId[$id] ?? null;
    }

    public function findBySlug(string $slug): ?ClientSnapshot
    {
        foreach ($this->byId as $snapshot) {
            if ($snapshot->slug === $slug) {
                return $snapshot;
            }
        }

        return null;
    }

    public function existsById(int $id): bool
    {
        return isset($this->byId[$id]);
    }
}
