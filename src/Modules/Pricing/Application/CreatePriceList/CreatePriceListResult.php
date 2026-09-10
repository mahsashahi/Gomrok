<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\CreatePriceList;

final readonly class CreatePriceListResult
{
    public function __construct(public int $priceListId)
    {
    }
}
