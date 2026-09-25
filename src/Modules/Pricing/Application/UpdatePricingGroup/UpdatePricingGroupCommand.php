<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\UpdatePricingGroup;

/**
 * Edits a pricing group's `name`/`slug`/`priority`/`deviceType` — every
 * mutable field {@see \Gomrok\Modules\Pricing\Domain\PricingGroup} exposes
 * except `countryCodes` and `status`, which stay {@see SetPricingGroupCountriesHandler}
 * / {@see ChangePricingGroupStatusHandler}'s own job (Admin composes all
 * three — see `AdminGroupsUpdateAction`). `currency` and `isDefault` are not
 * here at all: {@see \Gomrok\Modules\Pricing\Domain\PricingGroup}'s own
 * class docblock explains why they're intentionally immutable post-creation.
 *
 * `priority` is ignored (forced to 0) for the default group, mirroring
 * {@see \Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler}.
 */
final readonly class UpdatePricingGroupCommand
{
    public function __construct(
        public int $groupId,
        public string $name,
        public ?string $slug,
        public int $priority,
        public ?string $deviceType,
        public ?int $actorId = null,
    ) {
    }
}
