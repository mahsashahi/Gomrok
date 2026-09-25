<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class GroupDetail
{
    /**
     * @param list<string>           $countriesRaw
     * @param list<PriceListItem>    $priceLists
     * @param list<GroupPackageRow>  $packageRows
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public int $priority,
        public ?string $deviceType,
        public string $countriesDisplay,
        public array $countriesRaw,
        public string $currency,
        public bool $isDefault,
        public bool $isActive,
        public string $providersDisplay,
        public array $priceLists,
        public ?int $activePriceListId,
        public string $activePriceListName,
        public bool $activePriceListIsControl,
        public array $packageRows,
    ) {
    }
}
