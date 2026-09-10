<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\CreatePricingGroup;

final readonly class CreatePricingGroupResult
{
    public function __construct(
        public int $groupId,
        public string $slug,
        public bool $isDefault,
    ) {
    }
}
