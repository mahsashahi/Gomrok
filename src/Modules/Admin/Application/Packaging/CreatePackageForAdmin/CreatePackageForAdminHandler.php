<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin;

use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageCommand;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageHandler;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageResult;
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

final readonly class CreatePackageForAdminHandler
{
    public function __construct(
        private CreatePackageHandler $createPackage,
        private UpdatePackageHandler $updatePackage,
        private SetPackagePurchaseCapabilitiesHandler $setCapabilities,
        private SetDefaultPackagePriceHandler $setDefaultPrice,
    ) {
    }

    public function handle(CreatePackageForAdminCommand $command): Result
    {
        if (!$command->supportsOneTime && !$command->supportsSubscription) {
            return Result::err(DomainError::validation('package.no_purchase_type', 'A package needs at least one purchase type.'));
        }

        $created = $this->createPackage->handle(new CreatePackageCommand(
            $command->clientId,
            $command->code,
            $command->name,
            $command->description,
            null,
            $command->actorId,
        ));
        if ($created->isErr()) {
            return $created;
        }
        $result = $created->value();
        \assert($result instanceof CreatePackageResult);
        $packageId = $result->packageId;

        if ($command->badge !== null || $command->highlighted) {
            $updated = $this->updatePackage->handle(new UpdatePackageCommand(
                packageId: $packageId,
                badge: $command->badge,
                highlighted: $command->highlighted,
                actorId: $command->actorId,
            ));
            if ($updated->isErr()) {
                return $updated;
            }
        }

        $capabilities = [];
        if ($command->supportsOneTime) {
            $capabilities[] = new PurchaseCapabilityInput(PurchaseType::OneTimePayment->value, false, null, $command->durationMonths);
        }
        if ($command->supportsSubscription) {
            $capabilities[] = new PurchaseCapabilityInput(PurchaseType::Subscription->value, $command->hasTrial, $command->hasTrial ? $command->trialDays : null, $command->durationMonths);
        }
        $capabilitiesResult = $this->setCapabilities->handle(new SetPackagePurchaseCapabilitiesCommand($packageId, $capabilities, $command->actorId));
        if ($capabilitiesResult->isErr()) {
            return $capabilitiesResult;
        }

        if ($command->defaultPriceAmountMinor !== null && $command->defaultPriceCurrency !== null) {
            $priceResult = $this->setDefaultPrice->handle(new SetDefaultPackagePriceCommand($packageId, $command->defaultPriceAmountMinor, $command->defaultPriceCurrency, $command->actorId));
            if ($priceResult->isErr()) {
                return $priceResult;
            }
        }

        return Result::ok(new CreatePackageForAdminResult($packageId, $result->code));
    }
}
