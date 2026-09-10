<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceListFactor;

final readonly class SetPriceListFactorCommand
{
    public function __construct(
        public int $clientId,
        public int $priceListId,
        public string $factor,
        public ?int $actorId = null,
    ) {
    }
}
