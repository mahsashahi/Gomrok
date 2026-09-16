<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class PackagesTabResult
{
    /**
     * @param list<PackageListItem> $packages
     */
    public function __construct(
        public array $packages,
        public ?PackageDetail $selected,
    ) {
    }
}
