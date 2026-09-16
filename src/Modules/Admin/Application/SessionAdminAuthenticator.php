<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Domain\AdminSession;
use Gomrok\Modules\Admin\Domain\AdminSessionRepository;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Shared\Http\AdminAuthenticator;
use Gomrok\Shared\Http\AdminAuthResult;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Shared\Http\AuthRequestMeta;
use Psr\Clock\ClockInterface;

/**
 * The Admin-module implementation of {@see AdminAuthenticator}: hash the
 * presented token, look the session up by hash, check it hasn't expired or
 * been revoked, load the account and check its status, and throttle
 * `last_used_at` — mirrors {@see \Gomrok\Modules\Clients\Application\Authenticate\ApiKeyAuthenticator}'s
 * shape.
 */
final readonly class SessionAdminAuthenticator implements AdminAuthenticator
{
    /** Skip the `last_used_at` write unless the stored value is older than this. */
    private const LAST_USED_THROTTLE_SECONDS = 300;

    public function __construct(
        private AdminSessionRepository $sessions,
        private AdminUserRepository $users,
        private ClockInterface $clock,
    ) {
    }

    public function authenticate(?string $token, AuthRequestMeta $meta): AdminAuthResult
    {
        if ($token === null || trim($token) === '') {
            return AdminAuthResult::unauthorized();
        }

        $now = $this->clock->now();
        $session = $this->sessions->findByTokenHash(hash('sha256', trim($token)));
        if ($session === null || !$session->isValid($now)) {
            return AdminAuthResult::unauthorized();
        }

        $user = $this->users->findById($session->adminUserId());
        if ($user === null) {
            return AdminAuthResult::unauthorized();
        }

        if (!$user->isUsable()) {
            return AdminAuthResult::accountUnusable();
        }

        $this->throttleLastUsed($session, $now);

        return AdminAuthResult::success(new AuthenticatedAdmin($user->id() ?? 0, $user->name(), $user->email(), $user->role()->value));
    }

    private function throttleLastUsed(AdminSession $session, DateTimeImmutable $now): void
    {
        $lastUsed = $session->lastUsedAt();
        if ($lastUsed !== null && $lastUsed > $now->modify('-' . self::LAST_USED_THROTTLE_SECONDS . ' seconds')) {
            return;
        }

        $session->touch($now);
        $this->sessions->save($session);
    }
}
