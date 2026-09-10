<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Providers\Application\ProviderAccountCredentials;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderCatalog;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountCredentials;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountDirectory;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderAccountRepository;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderCatalog;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderGroupRepository;
use Gomrok\Modules\Providers\Infrastructure\PdoProviderTypeDeclarations;

/**
 * PHP-DI definitions for the Providers module (Phases 8–10). Use-case handlers,
 * the capability resolver and the {@see \Gomrok\Modules\Providers\Application\Routing\ProviderRouter}
 * are autowired.
 *
 * @return array<string, mixed>
 */
return [
    ProviderTypeDeclarations::class => get(PdoProviderTypeDeclarations::class),
    ProviderCatalog::class => get(PdoProviderCatalog::class),
    ProviderAccountRepository::class => get(PdoProviderAccountRepository::class),
    ProviderAccountDirectory::class => get(PdoProviderAccountDirectory::class),
    ProviderAccountCredentials::class => get(PdoProviderAccountCredentials::class),
    ProviderGroupRepository::class => get(PdoProviderGroupRepository::class),
];
