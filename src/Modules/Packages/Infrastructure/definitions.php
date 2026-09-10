<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageDirectory;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageRepository;

/**
 * PHP-DI definitions for the Packages module (Phase 11). Use-case handlers and
 * {@see \Gomrok\Modules\Packages\Application\PackageCatalog} are autowired.
 *
 * @return array<string, mixed>
 */
return [
    PackageRepository::class => get(PdoPackageRepository::class),
    PackageDirectory::class => get(PdoPackageDirectory::class),
];
