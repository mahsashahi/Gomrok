<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPricingGroupPackage;

/**
 * Set (upsert) how one package is priced / displayed in one pricing group.
 * `status` = `default` / `override` / `disabled`. `amountMinor` / `currency`
 * are required for `override` and must match the group currency.
 */
final readonly class SetPricingGroupPackageCommand
{
    public function __construct(
        public int $pricingGroupId,
        public int $packageId,
        public string $status = 'default',
        public ?int $amountMinor = null,
        public ?string $currency = null,
        public ?string $nameOverride = null,
        public ?string $badgeOverride = null,
        public ?bool $highlightedOverride = null,
        public int $displayOrder = 0,
        public ?int $actorId = null,
    ) {
    }
}
