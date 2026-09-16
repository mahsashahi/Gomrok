<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\SetAdminUserStatus;

/**
 * Set an admin user's account status directly to one of the three
 * {@see \Gomrok\Modules\Admin\Domain\AdminUserStatus} values — a single
 * command rather than separate enable/disable/lock methods, mirroring the
 * domain's own single `setStatus()` mutator. `Locked` is not set by anything
 * today (`AuthenticateAdminHandler`'s lockout is a rolling failed-attempt
 * count, never persisted as a status — see the handler's own docblock), but
 * this command is also the only way to clear it back to `Active` if a future
 * change ever does set it, so `Locked` is accepted here rather than treated
 * as unreachable.
 */
final readonly class SetAdminUserStatusCommand
{
    public function __construct(
        public int $adminUserId,
        public string $status,
        public ?int $actorId = null,
    ) {
    }
}
