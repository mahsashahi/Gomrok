<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;

/**
 * A {@see ClientDirectory} with a single configurable client — for use-case
 * tests in modules that only need "does this client exist / is it active".
 */
final class StubClientDirectory implements ClientDirectory
{
    public function __construct(
        private int $clientId = 7,
        private string $slug = 'stub-client',
        private bool $active = true,
    ) {
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
