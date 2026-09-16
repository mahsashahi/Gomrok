<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class PriceListItem
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isControl,
        public bool $isEnabled,
        public string $shareLabel,
        public bool $selected,
    ) {
    }
}
