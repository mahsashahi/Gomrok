<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\SetGroupPackagePriceForAdmin;

/**
 * The Packaging &amp; Pricing screen's "Edit price" modal for one package
 * inside one pricing group (Phase 27 Increment B). `status` is a
 * {@see \Gomrok\Modules\Pricing\Domain\PricingRowStatus} value: `default`
 * (use the package's base price), `override` (a group-specific amount, which
 * needs `amountMinor`/`currency`), or `disabled` (hidden in this group).
 */
final readonly class SetGroupPackagePriceForAdminCommand
{
    public function __construct(
        public int $pricingGroupId,
        public int $packageId,
        public string $status,
        public ?int $amountMinor,
        public ?string $currency,
        public ?int $actorId = null,
    ) {
    }
}
