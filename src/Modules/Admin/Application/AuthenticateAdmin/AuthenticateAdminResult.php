<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AuthenticateAdmin;

use DateTimeImmutable;

final readonly class AuthenticateAdminResult
{
    public function __construct(
        public int $adminUserId,
        public string $name,
        public string $role,
        public string $sessionToken,
        public DateTimeImmutable $expiresAt,
    ) {
    }
}
