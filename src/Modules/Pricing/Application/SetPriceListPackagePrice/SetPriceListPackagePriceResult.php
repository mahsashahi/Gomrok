<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice;

final readonly class SetPriceListPackagePriceResult
{
    public function __construct(public bool $created)
    {
    }
}
