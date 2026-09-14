<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;

final class InMemoryClientDirectory implements ClientDirectory
{
    /** @var array<int, ClientSnapshot> */
    private array $byId = [];

    /** @var array<string, string> keyed by "{clientId}:{purpose}" */
    private array $endpoints = [];

    public function add(ClientSnapshot $snapshot): void
    {
        $this->byId[$snapshot->id] = $snapshot;
    }

    public function setEndpoint(int $clientId, EndpointPurpose $purpose, string $url): void
    {
        $this->endpoints["{$clientId}:{$purpose->value}"] = $url;
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

    public function findActiveEndpointUrl(int $clientId, EndpointPurpose $purpose): ?string
    {
        return $this->endpoints["{$clientId}:{$purpose->value}"] ?? null;
    }
}
