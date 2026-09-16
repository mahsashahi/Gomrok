<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging\CreatePackageForAdmin;

final readonly class CreatePackageForAdminResult
{
    public function __construct(
        public int $packageId,
        public string $code,
    ) {
    }
}
