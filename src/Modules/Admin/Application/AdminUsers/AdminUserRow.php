<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AdminUsers;

final readonly class AdminUserRow
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $role,
        public string $status,
        public string $createdLabel,
        public bool $isSelf,
    ) {
    }
}
