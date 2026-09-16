<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\UpdatePackageForAdmin;

use Gomrok\Modules\Packages\Application\ChangePackageStatus\ChangePackageStatusHandler;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\PurchaseCapabilityInput;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesCommand;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesHandler;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageCommand;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageHandler;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceCommand;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceHandler;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

final readonly class UpdatePackageForAdminHandler
{
    public function __construct(
        private UpdatePackageHandler $updatePackage,
        private SetPackagePurchaseCapabilitiesHandler $setCapabilities,
        private SetDefaultPackagePriceHandler $setDefaultPrice,
        private ChangePackageStatusHandler $changeStatus,
    ) {
    }

    public function handle(UpdatePackageForAdminCommand $command): Result
    {
        if (!$command->supportsOneTime && !$command->supportsSubscription) {
            return Result::err(DomainError::validation('package.no_purchase_type', 'A package needs at least one purchase type.'));
        }

        $updated = $this->updatePackage->handle(new UpdatePackageCommand(
            packageId: $command->packageId,
            name: $command->name,
            description: $command->description,
            clearDescription: $command->description === null,
            badge: $command->badge,
            highlighted: $command->highlighted,
            clearBadge: $command->badge === null,
            actorId: $command->actorId,
        ));
        if ($updated->isErr()) {
            return $updated;
        }

        $capabilities = [];
        if ($command->supportsOneTime) {
            $capabilities[] = new PurchaseCapabilityInput(PurchaseType::OneTimePayment->value, false, null, $command->durationMonths);
        }
        if ($command->supportsSubscription) {
            $capabilities[] = new PurchaseCapabilityInput(PurchaseType::Subscription->value, $command->hasTrial, $command->hasTrial ? $command->trialDays : null, $command->durationMonths);
        }
        $capabilitiesResult = $this->setCapabilities->handle(new SetPackagePurchaseCapabilitiesCommand($command->packageId, $capabilities, $command->actorId));
        if ($capabilitiesResult->isErr()) {
            return $capabilitiesResult;
        }

        if ($command->defaultPriceAmountMinor !== null && $command->defaultPriceCurrency !== null) {
            $priceResult = $this->setDefaultPrice->handle(new SetDefaultPackagePriceCommand($command->packageId, $command->defaultPriceAmountMinor, $command->defaultPriceCurrency, $command->actorId));
            if ($priceResult->isErr()) {
                return $priceResult;
            }
        }

        $statusResult = $command->active
            ? $this->changeStatus->enable($command->packageId, $command->actorId)
            : $this->changeStatus->disable($command->packageId, $command->actorId);
        if ($statusResult->isErr()) {
            return $statusResult;
        }

        return Result::ok(null);
    }
}
