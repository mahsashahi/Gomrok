<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ResetAdminUserPassword;

use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Sets a new password for an existing admin user, chosen and typed by the
 * admin performing the reset — never generated or shown back, since only the
 * hash is ever persisted (`AdminUser::setPasswordHash()`). The audit entry
 * records that a reset happened, never the new password itself.
 */
final readonly class ResetAdminUserPasswordHandler
{
    private const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private AdminUserRepository $users,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ResetAdminUserPasswordCommand $command): Result
    {
        if (\strlen($command->newPassword) < self::MIN_PASSWORD_LENGTH) {
            return Result::err(DomainError::validation(
                'admin_user.password_too_short',
                'A password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
            ));
        }

        $user = $this->users->findById($command->adminUserId);
        if ($user === null) {
            return Result::err(DomainError::notFound('admin_user.not_found', "Admin user {$command->adminUserId} was not found."));
        }

        $now = $this->clock->now();
        $user->setPasswordHash(password_hash($command->newPassword, \PASSWORD_DEFAULT), $now);

        $this->transactions->run(function () use ($user, $command): void {
            $this->users->save($user);
            $userId = $user->id();
            \assert($userId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, null, 'admin_user.password_reset')
                : AuditEntry::forSystem('admin_user.password_reset');

            $this->audit->record($entry->withTarget('admin_user', $userId));
        });

        return Result::ok(null);
    }
}
