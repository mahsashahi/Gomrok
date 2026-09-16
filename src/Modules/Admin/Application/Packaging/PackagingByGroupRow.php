<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class PackagingByGroupRow
{
    public function __construct(
        public string $groupSlug,
        public string $groupName,
        public string $countryLabel,
        public string $price,
        public ?string $defaultCurrencyPrice,
        public string $status,
        public string $providersLabel,
    ) {
    }
}
