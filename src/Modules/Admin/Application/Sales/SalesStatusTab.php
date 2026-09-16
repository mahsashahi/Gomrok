<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Sales;

final readonly class SalesStatusTab
{
    public function __construct(
        public string $key,
        public string $label,
        public int $count,
        public bool $active,
    ) {
    }
}
