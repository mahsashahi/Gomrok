<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AdminUsers;

use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;

/**
 * Builds the Admin Users screen (Phase 27) — a flat table, no master-detail:
 * `AdminUser` has almost nothing beyond what a row already shows (name,
 * email, role, status are the entire aggregate besides the password hash),
 * so a separate detail panel would just repeat the row.
 */
final readonly class AdminUsersScreenHandler
{
    public function __construct(private AdminUserRepository $users)
    {
    }

    public function build(int $currentAdminId): AdminUsersScreenResult
    {
        $rows = array_map(
            fn (AdminUser $u): AdminUserRow => new AdminUserRow(
                $u->id() ?? 0,
                $u->name(),
                $u->email(),
                $u->role()->value,
                $u->status()->value,
                substr($u->createdAt()->format('Y-m-d H:i:s'), 0, 10),
                $u->id() === $currentAdminId,
            ),
            $this->users->all(),
        );

        return new AdminUsersScreenResult($rows);
    }
}
