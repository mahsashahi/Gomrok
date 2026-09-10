<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\CreateProviderGroup;

/**
 * Define a routing group for a client. `deviceType` null = applies to any
 * surface; `currencyCode` null = don't gate on currency. Exactly one default
 * group is allowed per (client, device type).
 */
final readonly class CreateProviderGroupCommand
{
    public function __construct(
        public int $clientId,
        public string $name,
        public bool $isDefault = false,
        public ?string $slug = null,
        public ?string $deviceType = null,
        public ?string $currencyCode = null,
        public ?int $actorId = null,
    ) {
    }
}
