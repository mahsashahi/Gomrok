<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class GroupListItem
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $countryLabel,
        public bool $isDefault,
        public bool $selected,
    ) {
    }
}
