<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages;

final readonly class ReorderPricingGroupPackagesCommand
{
    /**
     * @param list<int> $packageIdsInOrder
     */
    public function __construct(
        public int $pricingGroupId,
        public array $packageIdsInOrder,
        public ?int $actorId = null,
    ) {
    }
}
