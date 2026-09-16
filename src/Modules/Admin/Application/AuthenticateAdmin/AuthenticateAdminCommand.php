<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AuthenticateAdmin;

final readonly class AuthenticateAdminCommand
{
    public function __construct(
        public string $email,
        public string $password,
        public ?string $ip,
        public ?string $userAgent,
    ) {
    }
}
