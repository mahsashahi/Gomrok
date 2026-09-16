<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\SetAdminUserStatus;

use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Modules\Admin\Domain\AdminUserStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Idempotent. Refuses to leave the acting admin's own account non-active —
 * an admin can reactivate themselves (a harmless no-op) but never disable or
 * lock their own session out from under them. No such guard exists anywhere
 * else in the codebase to reuse; this is this handler's own safety rule,
 * the same shape as {@see \Gomrok\Modules\Pricing\Application\ChangePricingGroupStatus\ChangePricingGroupStatusHandler}'s
 * "cannot disable the default group."
 */
final readonly class SetAdminUserStatusHandler
{
    public function __construct(
        private AdminUserRepository $users,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetAdminUserStatusCommand $command): Result
    {
        $status = AdminUserStatus::tryFrom($command->status);
        if ($status === null) {
            return Result::err(DomainError::validation('admin_user.unknown_status', "Unknown status '{$command->status}'.", ['status' => $command->status]));
        }

        $user = $this->users->findById($command->adminUserId);
        if ($user === null) {
            return Result::err(DomainError::notFound('admin_user.not_found', "Admin user {$command->adminUserId} was not found."));
        }

        if ($status !== AdminUserStatus::Active && $command->actorId === $command->adminUserId) {
            return Result::err(DomainError::forbidden('admin_user.cannot_disable_self', 'You cannot disable or lock your own account.'));
        }

        if ($user->status() === $status) {
            return Result::ok(null);
        }

        $before = $user->status()->value;
        $now = $this->clock->now();
        $user->setStatus($status, $now);

        $this->transactions->run(function () use ($user, $before, $status, $command): void {
            $this->users->save($user);
            $userId = $user->id();
            \assert($userId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, null, 'admin_user.status_changed')
                : AuditEntry::forSystem('admin_user.status_changed');

            $this->audit->record(
                $entry->withTarget('admin_user', $userId)
                    ->withChange(['status' => $before], ['status' => $status->value]),
            );
        });

        return Result::ok(null);
    }
}
