<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class GroupPackageRow
{
    public function __construct(
        public int $packageId,
        public string $code,
        public string $name,
        public ?string $defaultPrice,
        public string $groupPrice,
        public ?string $groupPriceMonthly,
        public ?string $defaultCurrencyPrice,
        public ?string $defaultCurrencyPriceMonthly,
        public string $status,
        public bool $highlighted,
        public ?string $badge,
        public string $overrideStatus,
        public ?int $overrideAmountMinor,
    ) {
    }
}
