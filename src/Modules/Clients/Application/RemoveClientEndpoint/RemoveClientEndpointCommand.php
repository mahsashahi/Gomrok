<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\RemoveClientEndpoint;

final readonly class RemoveClientEndpointCommand
{
    public function __construct(
        public int $clientId,
        public string $purpose,
    ) {
    }
}
