<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\LogoutAdminHandler;
use Gomrok\Modules\Admin\Domain\AdminSession;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryAdminSessionRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogoutAdminHandlerTest extends TestCase
{
    #[Test]
    public function revokesTheSessionBehindTheToken(): void
    {
        $now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
        $sessions = new InMemoryAdminSessionRepository();
        $token = 'raw-token';
        $session = AdminSession::issue(1, hash('sha256', $token), null, null, $now->modify('+1 hour'), $now);
        $sessions->save($session);

        $handler = new LogoutAdminHandler($sessions, new FrozenClock('2026-09-15T12:00:00+00:00'));
        $handler->handle($token);

        self::assertFalse($session->isValid($now));
    }

    #[Test]
    public function anUnknownTokenIsASilentNoOp(): void
    {
        $sessions = new InMemoryAdminSessionRepository();
        $handler = new LogoutAdminHandler($sessions, new FrozenClock('2026-09-15T12:00:00+00:00'));

        $handler->handle('nonexistent');

        self::assertNull($sessions->findByTokenHash(hash('sha256', 'nonexistent')));
    }
}
