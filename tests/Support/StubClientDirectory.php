<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;

/**
 * A {@see ClientDirectory} with a single configurable client — for use-case
 * tests in modules that only need "does this client exist / is it active".
 */
final class StubClientDirectory implements ClientDirectory
{
    /** @var array<string, string> keyed by purpose value */
    private array $endpoints = [];

    public function __construct(
        private int $clientId = 7,
        private string $slug = 'stub-client',
        private bool $active = true,
    ) {
    }

    public function withEndpoint(EndpointPurpose $purpose, string $url): self
    {
        $this->endpoints[$purpose->value] = $url;

        return $this;
    }

    public function findById(int $id): ?ClientSnapshot
    {
        return $id === $this->clientId ? $this->snapshot() : null;
    }

    public function findBySlug(string $slug): ?ClientSnapshot
    {
        return $slug === $this->slug ? $this->snapshot() : null;
    }

    public function existsById(int $id): bool
    {
        return $id === $this->clientId;
    }

    public function all(): array
    {
        return [$this->snapshot()];
    }

    public function findActiveEndpointUrl(int $clientId, EndpointPurpose $purpose): ?string
    {
        return $clientId === $this->clientId ? ($this->endpoints[$purpose->value] ?? null) : null;
    }

    private function snapshot(): ClientSnapshot
    {
        return new ClientSnapshot(
            $this->clientId,
            $this->slug,
            'Stub Client',
            $this->active ? ClientStatus::Active : ClientStatus::Disabled,
            'EUR',
            'DE',
            'UTC',
        );
    }
}
