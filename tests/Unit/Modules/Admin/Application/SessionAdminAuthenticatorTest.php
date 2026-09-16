<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\SessionAdminAuthenticator;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminSession;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserStatus;
use Gomrok\Shared\Http\AuthRequestMeta;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryAdminSessionRepository;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SessionAdminAuthenticatorTest extends TestCase
{
    private DateTimeImmutable $now;
    private InMemoryAdminUserRepository $users;
    private InMemoryAdminSessionRepository $sessions;
    private SessionAdminAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
        $this->users = new InMemoryAdminUserRepository();
        $this->sessions = new InMemoryAdminSessionRepository();
        $this->authenticator = new SessionAdminAuthenticator($this->sessions, $this->users, new FrozenClock('2026-09-15T12:00:00+00:00'));
    }

    #[Test]
    public function aValidTokenResolvesTheAdmin(): void
    {
        $user = AdminUser::create('Ada Admin', 'admin@gomrok.test', 'hash', AdminRole::Admin, $this->now);
        $this->users->save($user);
        $userId = $user->id();
        \assert($userId !== null);

        $token = 'raw-token';
        $session = AdminSession::issue($userId, hash('sha256', $token), null, null, $this->now->modify('+1 hour'), $this->now);
        $this->sessions->save($session);

        $result = $this->authenticator->authenticate($token, new AuthRequestMeta());

        self::assertTrue($result->ok);
        self::assertSame('Ada Admin', $result->admin()->name);
    }

    #[Test]
    public function noTokenIsUnauthorized(): void
    {
        $result = $this->authenticator->authenticate(null, new AuthRequestMeta());

        self::assertFalse($result->ok);
        self::assertSame(401, $result->failureStatus);
    }

    #[Test]
    public function anUnknownTokenIsUnauthorized(): void
    {
        $result = $this->authenticator->authenticate('nonexistent', new AuthRequestMeta());

        self::assertFalse($result->ok);
        self::assertSame(401, $result->failureStatus);
    }

    #[Test]
    public function anExpiredSessionIsUnauthorized(): void
    {
        $user = AdminUser::create('Ada Admin', 'admin@gomrok.test', 'hash', AdminRole::Admin, $this->now);
        $this->users->save($user);
        $userId = $user->id();
        \assert($userId !== null);

        $token = 'raw-token';
        $session = AdminSession::issue($userId, hash('sha256', $token), null, null, $this->now->modify('-1 hour'), $this->now->modify('-2 hours'));
        $this->sessions->save($session);

        $result = $this->authenticator->authenticate($token, new AuthRequestMeta());

        self::assertFalse($result->ok);
    }

    #[Test]
    public function aRevokedSessionIsUnauthorized(): void
    {
        $user = AdminUser::create('Ada Admin', 'admin@gomrok.test', 'hash', AdminRole::Admin, $this->now);
        $this->users->save($user);
        $userId = $user->id();
        \assert($userId !== null);

        $token = 'raw-token';
        $session = AdminSession::issue($userId, hash('sha256', $token), null, null, $this->now->modify('+1 hour'), $this->now);
        $session->revoke($this->now);
        $this->sessions->save($session);

        $result = $this->authenticator->authenticate($token, new AuthRequestMeta());

        self::assertFalse($result->ok);
    }

    #[Test]
    public function aDisabledAccountsSessionIsAccountUnusable(): void
    {
        $user = AdminUser::create('Dana Disabled', 'dana@gomrok.test', 'hash', AdminRole::SupportAgent, $this->now);
        $user->setStatus(AdminUserStatus::Disabled, $this->now);
        $this->users->save($user);
        $userId = $user->id();
        \assert($userId !== null);

        $token = 'raw-token';
        $session = AdminSession::issue($userId, hash('sha256', $token), null, null, $this->now->modify('+1 hour'), $this->now);
        $this->sessions->save($session);

        $result = $this->authenticator->authenticate($token, new AuthRequestMeta());

        self::assertFalse($result->ok);
        self::assertSame(403, $result->failureStatus);
    }
}
