<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\ReorderPricingGroupPackages;

use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Shared\Domain\Result;

/**
 * The Packaging &amp; Pricing screen's drag-to-reorder (Phase 27 Increment
 * B). {@see SetPricingGroupPackageHandler} always upserts a *full* row — no
 * "just change displayOrder" mode — so each package's existing status/price/
 * overrides are read first and resubmitted unchanged, only `displayOrder`
 * moves. A package with no row yet (still "default", never priced in this
 * group) gets one created purely to carry its position — still `status:
 * default`, no price override introduced by reordering alone.
 */
final readonly class ReorderPricingGroupPackagesHandler
{
    public function __construct(
        private PricingGroupPackageRepository $rows,
        private SetPricingGroupPackageHandler $setPackage,
    ) {
    }

    public function handle(ReorderPricingGroupPackagesCommand $command): Result
    {
        foreach ($command->packageIdsInOrder as $index => $packageId) {
            $existing = $this->rows->find($command->pricingGroupId, $packageId);

            $result = $this->setPackage->handle(new SetPricingGroupPackageCommand(
                pricingGroupId: $command->pricingGroupId,
                packageId: $packageId,
                status: $existing?->status()->value ?? 'default',
                amountMinor: $existing?->amountMinor(),
                currency: $existing?->currencyCode(),
                nameOverride: $existing?->nameOverride(),
                badgeOverride: $existing?->badgeOverride(),
                highlightedOverride: $existing?->highlightedOverride(),
                displayOrder: $index,
                actorId: $command->actorId,
            ));
            if ($result->isErr()) {
                return $result;
            }
        }

        return Result::ok(null);
    }
}
