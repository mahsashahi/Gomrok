<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\SetClientEndpoint;

/**
 * Register or replace the callback URL for one purpose on a client.
 */
final readonly class SetClientEndpointCommand
{
    public function __construct(
        public int $clientId,
        public string $purpose,
        public string $url,
    ) {
    }
}
