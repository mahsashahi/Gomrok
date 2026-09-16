<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ResetAdminUserPassword;

final readonly class ResetAdminUserPasswordCommand
{
    public function __construct(
        public int $adminUserId,
        public string $newPassword,
        public ?int $actorId = null,
    ) {
    }
}
