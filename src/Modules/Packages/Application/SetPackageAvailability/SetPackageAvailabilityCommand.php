<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\SetPackageAvailability;

/**
 * Replace a package's market availability in one shot. Each list is a full set —
 * whatever is passed becomes the new value. An empty list means "available
 * everywhere for that dimension" (Phase 11 Q2).
 */
final readonly class SetPackageAvailabilityCommand
{
    /**
     * @param list<string> $countries          ISO 3166-1 alpha-2
     * @param list<string> $currencies         ISO 4217
     * @param list<string> $methods            `PaymentMethod` values
     * @param list<int>    $providerAccountIds
     */
    public function __construct(
        public int $packageId,
        public array $countries = [],
        public array $currencies = [],
        public array $methods = [],
        public array $providerAccountIds = [],
        public ?int $actorId = null,
    ) {
    }
}
