<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\PackageProviderDefinitionDirectory;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageDirectory;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageProviderDefinitionDirectory;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Infrastructure\PdoPackageRepository;

/**
 * PHP-DI definitions for the Packages module (Phases 11–12). Use-case handlers,
 * {@see \Gomrok\Modules\Packages\Application\PackageCatalog} and
 * {@see \Gomrok\Modules\Packages\Application\PackagePurchaseCapabilityResolver}
 * are autowired.
 *
 * @return array<string, mixed>
 */
return [
    PackageRepository::class => get(PdoPackageRepository::class),
    PackageDirectory::class => get(PdoPackageDirectory::class),
    PackageProviderDefinitionRepository::class => get(PdoPackageProviderDefinitionRepository::class),
    PackageProviderDefinitionDirectory::class => get(PdoPackageProviderDefinitionDirectory::class),
];
