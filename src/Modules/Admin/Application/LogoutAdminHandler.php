<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application;

use Gomrok\Modules\Admin\Domain\AdminSessionRepository;
use Psr\Clock\ClockInterface;

/**
 * Revokes the session behind a presented raw cookie token (Phase 27).
 * Idempotent — an unknown or already-revoked token is a silent no-op, since
 * the caller's goal ("I am now logged out") is already true either way.
 */
final readonly class LogoutAdminHandler
{
    public function __construct(
        private AdminSessionRepository $sessions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(string $token): void
    {
        $session = $this->sessions->findByTokenHash(hash('sha256', $token));
        if ($session === null) {
            return;
        }

        $session->revoke($this->clock->now());
        $this->sessions->save($session);
    }
}
