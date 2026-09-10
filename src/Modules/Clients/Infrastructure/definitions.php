<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Clients\Application\Authenticate\ApiKeyAuthenticator;
use Gomrok\Modules\Clients\Application\Authenticate\AuthAttemptLog;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Domain\ApiKeyGenerator;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Infrastructure\PdoAuthAttemptLog;
use Gomrok\Modules\Clients\Infrastructure\PdoClientApiKeyRepository;
use Gomrok\Modules\Clients\Infrastructure\PdoClientDirectory;
use Gomrok\Modules\Clients\Infrastructure\PdoClientRepository;
use Gomrok\Modules\Clients\Infrastructure\RandomApiKeyGenerator;
use Gomrok\Shared\Http\ClientAuthenticator;

/**
 * PHP-DI definitions for the Clients module. Loaded by
 * {@see \Gomrok\Bootstrap\ContainerFactory}. Use-case handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    ClientRepository::class => get(PdoClientRepository::class),
    ClientApiKeyRepository::class => get(PdoClientApiKeyRepository::class),
    ClientDirectory::class => get(PdoClientDirectory::class),
    ApiKeyGenerator::class => get(RandomApiKeyGenerator::class),

    // Phase 7 — API-key authentication.
    ClientAuthenticator::class => get(ApiKeyAuthenticator::class),
    AuthAttemptLog::class => get(PdoAuthAttemptLog::class),
];
