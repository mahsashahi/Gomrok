<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Admin\Application\SessionAdminAuthenticator;
use Gomrok\Modules\Admin\Domain\AdminLoginAttemptRepository;
use Gomrok\Modules\Admin\Domain\AdminSessionRepository;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Modules\Admin\Infrastructure\PdoAdminLoginAttemptRepository;
use Gomrok\Modules\Admin\Infrastructure\PdoAdminSessionRepository;
use Gomrok\Modules\Admin\Infrastructure\PdoAdminUserRepository;
use Gomrok\Shared\Http\AdminAuthenticator;

/**
 * PHP-DI definitions for the Admin module (Phase 27). Use-case handlers are
 * autowired.
 *
 * @return array<string, mixed>
 */
return [
    AdminUserRepository::class => get(PdoAdminUserRepository::class),
    AdminSessionRepository::class => get(PdoAdminSessionRepository::class),
    AdminLoginAttemptRepository::class => get(PdoAdminLoginAttemptRepository::class),

    // Phase 27 — admin session authentication.
    AdminAuthenticator::class => get(SessionAdminAuthenticator::class),
];
