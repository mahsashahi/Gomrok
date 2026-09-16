<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\UpdatePackageForAdmin;

/**
 * The admin panel's "Edit package" modal (Phase 27 Increment B) — composes
 * `UpdatePackageHandler`, `SetPackagePurchaseCapabilitiesHandler`,
 * `SetDefaultPackagePriceHandler` and `ChangePackageStatusHandler`.
 */
final readonly class UpdatePackageForAdminCommand
{
    public function __construct(
        public int $packageId,
        public string $name,
        public ?string $description,
        public ?string $badge,
        public bool $highlighted,
        public bool $supportsOneTime,
        public bool $supportsSubscription,
        public ?int $durationMonths,
        public bool $hasTrial,
        public ?int $trialDays,
        public ?int $defaultPriceAmountMinor,
        public ?string $defaultPriceCurrency,
        public bool $active,
        public ?int $actorId = null,
    ) {
    }
}
