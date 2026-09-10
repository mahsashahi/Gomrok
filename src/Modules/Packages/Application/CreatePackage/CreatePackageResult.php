<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\CreatePackage;

final readonly class CreatePackageResult
{
    public function __construct(
        public int $packageId,
        public string $code,
    ) {
    }
}
