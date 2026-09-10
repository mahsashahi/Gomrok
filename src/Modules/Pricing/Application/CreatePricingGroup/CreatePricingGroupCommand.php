<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\CreatePricingGroup;

/**
 * Define a pricing group for a client. `deviceType` null = any surface.
 * `priority` is ignored for `isDefault` groups (they are always resolved last).
 * Exactly one default group is allowed per client.
 */
final readonly class CreatePricingGroupCommand
{
    public function __construct(
        public int $clientId,
        public string $name,
        public string $currency,
        public bool $isDefault = false,
        public ?string $slug = null,
        public ?string $deviceType = null,
        public int $priority = 100,
        public ?int $actorId = null,
    ) {
    }
}
