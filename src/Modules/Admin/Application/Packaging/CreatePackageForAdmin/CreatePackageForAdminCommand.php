<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin;

/**
 * The admin panel's "New package" modal (Phase 27 Increment B) — one form
 * submission that composes `CreatePackageHandler`, `UpdatePackageHandler`
 * (display fields), `SetPackagePurchaseCapabilitiesHandler`, and
 * `SetDefaultPackagePriceHandler` into the single action the modal presents.
 */
final readonly class CreatePackageForAdminCommand
{
    public function __construct(
        public int $clientId,
        public string $code,
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
        public ?int $actorId = null,
    ) {
    }
}
