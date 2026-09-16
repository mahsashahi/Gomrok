<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class PackageListItem
{
    public function __construct(
        public string $code,
        public string $name,
        public ?string $defaultPrice,
        public string $durationLabel,
        public ?string $trialLabel,
        public ?string $badge,
        public bool $highlighted,
        public string $status,
        public bool $selected,
    ) {
    }
}
