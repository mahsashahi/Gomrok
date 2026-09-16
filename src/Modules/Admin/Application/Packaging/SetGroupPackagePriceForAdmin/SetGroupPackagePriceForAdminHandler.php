<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\SetGroupPackagePriceForAdmin;

use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageHandler;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Shared\Domain\Result;

/**
 * {@see SetPricingGroupPackageHandler} always upserts a *full* row, including
 * `displayOrder` and any name/badge/highlighted overrides — this wrapper
 * reads the package's existing row first (if any) so the "Edit price" modal
 * only has to submit status + amount, without silently resetting the
 * package's position or cosmetic overrides.
 */
final readonly class SetGroupPackagePriceForAdminHandler
{
    public function __construct(
        private PricingGroupPackageRepository $rows,
        private SetPricingGroupPackageHandler $setPackage,
    ) {
    }

    public function handle(SetGroupPackagePriceForAdminCommand $command): Result
    {
        $existing = $this->rows->find($command->pricingGroupId, $command->packageId);
        $displayOrder = $existing !== null ? $existing->displayOrder() : \count($this->rows->forGroup($command->pricingGroupId));

        return $this->setPackage->handle(new SetPricingGroupPackageCommand(
            pricingGroupId: $command->pricingGroupId,
            packageId: $command->packageId,
            status: $command->status,
            amountMinor: $command->amountMinor,
            currency: $command->currency,
            nameOverride: $existing?->nameOverride(),
            badgeOverride: $existing?->badgeOverride(),
            highlightedOverride: $existing?->highlightedOverride(),
            displayOrder: $displayOrder,
            actorId: $command->actorId,
        ));
    }
}
