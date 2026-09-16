<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\AuthenticateAdmin;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Domain\AdminLoginAttempt;
use Gomrok\Modules\Admin\Domain\AdminLoginAttemptRepository;
use Gomrok\Modules\Admin\Domain\AdminSession;
use Gomrok\Modules\Admin\Domain\AdminSessionRepository;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Shared\Application\TokenGenerator;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Admin login (Phase 27). A fixed-window lockout (CLAUDE.md: "add login
 * attempt logging") rejects a login outright once too many failed attempts
 * land for the same email within the window — checked before the password is
 * even verified, so a locked-out attacker can't keep guessing. On success,
 * issues a {@see AdminSession} (Q3: DB-backed hashed token) — the caller sets
 * the raw token as an `HttpOnly` cookie; only its hash is ever persisted.
 */
final readonly class AuthenticateAdminHandler
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_WINDOW_MINUTES = 15;
    private const SESSION_TTL_HOURS = 12;

    public function __construct(
        private AdminUserRepository $users,
        private AdminSessionRepository $sessions,
        private AdminLoginAttemptRepository $attempts,
        private TokenGenerator $tokens,
        private ClockInterface $clock,
    ) {
    }

    public function handle(AuthenticateAdminCommand $command): Result
    {
        $now = $this->clock->now();
        $email = AdminUser::normaliseEmail($command->email);
        $ip = $command->ip ?? 'unknown';

        $since = $now->modify('-' . self::LOCKOUT_WINDOW_MINUTES . ' minutes');
        if ($this->attempts->countFailedSince($email, $since) >= self::MAX_FAILED_ATTEMPTS) {
            $this->recordAttempt($email, null, $ip, false, $now);

            return Result::err(DomainError::unauthorized(
                'admin.too_many_attempts',
                'Too many failed login attempts. Try again later.',
            ));
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || !$user->matchesPassword($command->password)) {
            $this->recordAttempt($email, $user?->id(), $ip, false, $now);

            return Result::err(DomainError::unauthorized('admin.invalid_credentials', 'Incorrect email or password.'));
        }

        if (!$user->isUsable()) {
            $this->recordAttempt($email, $user->id(), $ip, false, $now);

            return Result::err(DomainError::unauthorized(
                'admin.account_' . $user->status()->value,
                "This account is {$user->status()->value}.",
            ));
        }

        $token = $this->tokens->urlSafe(32);
        $expiresAt = $now->modify('+' . self::SESSION_TTL_HOURS . ' hours');

        $userId = $user->id();
        \assert($userId !== null);

        $session = AdminSession::issue($userId, hash('sha256', $token), $command->ip, $command->userAgent, $expiresAt, $now);
        $this->sessions->save($session);

        $this->recordAttempt($email, $userId, $ip, true, $now);

        return Result::ok(new AuthenticateAdminResult($userId, $user->name(), $user->role()->value, $token, $expiresAt));
    }

    private function recordAttempt(string $email, ?int $adminUserId, string $ip, bool $succeeded, DateTimeImmutable $now): void
    {
        $this->attempts->save(AdminLoginAttempt::record($email, $adminUserId, $ip, $succeeded, $now));
    }
}
