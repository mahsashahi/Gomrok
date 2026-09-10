<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\CreatePriceList;

/**
 * Create a non-control A/B price list inside a pricing group. `factor` is a
 * decimal string ("0.9000"); the control list is created with the group and is
 * never made this way.
 */
final readonly class CreatePriceListCommand
{
    public function __construct(
        public int $clientId,
        public int $pricingGroupId,
        public string $name,
        public string $factor = '1.0000',
        public ?int $actorId = null,
    ) {
    }
}
