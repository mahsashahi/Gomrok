<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AdminUsers;

final readonly class AdminUsersScreenResult
{
    /**
     * @param list<AdminUserRow> $rows
     */
    public function __construct(public array $rows)
    {
    }
}
