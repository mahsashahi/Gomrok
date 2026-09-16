<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\CreateAdminUser;

use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Creates an admin panel user. Not client-scoped — an {@see AdminUser} is a
 * global operator account (Phase 27 Q5), so its audit entry carries no
 * `clientId`.
 */
final readonly class CreateAdminUserHandler
{
    private const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private AdminUserRepository $users,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreateAdminUserCommand $command): Result
    {
        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('admin_user.name_required', 'A name is required.'));
        }

        $email = trim($command->email);
        if (filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            return Result::err(DomainError::validation('admin_user.invalid_email', "'{$email}' is not a valid email address."));
        }

        if (\strlen($command->password) < self::MIN_PASSWORD_LENGTH) {
            return Result::err(DomainError::validation(
                'admin_user.password_too_short',
                'A password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.',
            ));
        }

        $role = AdminRole::tryFrom($command->role);
        if ($role === null) {
            return Result::err(DomainError::validation('admin_user.unknown_role', "Unknown role '{$command->role}'.", ['role' => $command->role]));
        }

        if ($this->users->findByEmail($email) !== null) {
            return Result::err(DomainError::conflict('admin_user.email_taken', "An admin user with email '{$email}' already exists.", ['email' => $email]));
        }

        $now = $this->clock->now();
        $user = AdminUser::create($name, $email, password_hash($command->password, \PASSWORD_DEFAULT), $role, $now);

        $this->transactions->run(function () use ($user, $command): void {
            $this->users->save($user);
            $userId = $user->id();
            \assert($userId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, null, 'admin_user.created')
                : AuditEntry::forSystem('admin_user.created');

            $this->audit->record(
                $entry->withTarget('admin_user', $userId)
                    ->withChange(null, ['name' => $user->name(), 'email' => $user->email(), 'role' => $user->role()->value]),
            );
        });

        $userId = $user->id();
        \assert($userId !== null);

        return Result::ok(new CreateAdminUserResult($userId));
    }
}
