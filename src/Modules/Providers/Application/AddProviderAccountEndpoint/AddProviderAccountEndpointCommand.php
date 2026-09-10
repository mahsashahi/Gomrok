<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint;

/**
 * Register (or rotate) an inbound endpoint for a provider account. Any existing
 * active endpoint of the same kind is deactivated.
 */
final readonly class AddProviderAccountEndpointCommand
{
    public function __construct(
        public int $accountId,
        public string $kind,
        public ?string $signingSecret = null,
        public ?int $actorId = null,
    ) {
    }
}
