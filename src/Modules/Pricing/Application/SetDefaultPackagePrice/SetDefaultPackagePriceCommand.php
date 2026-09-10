<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice;

/**
 * Set (upsert) a package's one baseline price. `amountMinor` is in
 * `currency`'s minor unit.
 */
final readonly class SetDefaultPackagePriceCommand
{
    public function __construct(
        public int $packageId,
        public int $amountMinor,
        public string $currency,
        public ?int $actorId = null,
    ) {
    }
}
