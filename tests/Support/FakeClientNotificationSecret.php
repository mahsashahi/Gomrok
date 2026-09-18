<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Clients\Application\ClientNotificationSecret;

final class FakeClientNotificationSecret implements ClientNotificationSecret
{
    /** @var array<int, string> */
    private array $secrets = [];

    public function set(int $clientId, string $secret): void
    {
        $this->secrets[$clientId] = $secret;
    }

    public function secretFor(int $clientId): ?string
    {
        return $this->secrets[$clientId] ?? null;
    }
}
