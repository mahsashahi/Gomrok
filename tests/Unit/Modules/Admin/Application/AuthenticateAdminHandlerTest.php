<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\AuthenticateAdmin\AuthenticateAdminCommand;
use Gomrok\Modules\Admin\Application\AuthenticateAdmin\AuthenticateAdminHandler;
use Gomrok\Modules\Admin\Application\AuthenticateAdmin\AuthenticateAdminResult;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserStatus;
use Gomrok\Tests\Support\FixedTokenGenerator;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryAdminLoginAttemptRepository;
use Gomrok\Tests\Support\InMemoryAdminSessionRepository;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuthenticateAdminHandlerTest extends TestCase
{
    private DateTimeImmutable $now;
    private InMemoryAdminUserRepository $users;
    private InMemoryAdminSessionRepository $sessions;
    private InMemoryAdminLoginAttemptRepository $attempts;
    private AuthenticateAdminHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
        $this->users = new InMemoryAdminUserRepository();
        $this->sessions = new InMemoryAdminSessionRepository();
        $this->attempts = new InMemoryAdminLoginAttemptRepository();

        $this->handler = new AuthenticateAdminHandler(
            $this->users,
            $this->sessions,
            $this->attempts,
            new FixedTokenGenerator(),
            new FrozenClock('2026-09-15T12:00:00+00:00'),
        );

        $user = AdminUser::create('Ada Admin', 'admin@gomrok.test', password_hash('correct-horse', \PASSWORD_DEFAULT), AdminRole::Admin, $this->now);
        $this->users->save($user);
    }

    #[Test]
    public function issuesASessionForCorrectCredentials(): void
    {
        $result = $this->handler->handle(new AuthenticateAdminCommand('admin@gomrok.test', 'correct-horse', '127.0.0.1', 'test-agent'));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof AuthenticateAdminResult);
        self::assertSame('Ada Admin', $value->name);
        self::assertSame('admin', $value->role);
        self::assertNotSame('', $value->sessionToken);

        $session = $this->sessions->findByTokenHash(hash('sha256', $value->sessionToken));
        self::assertNotNull($session);
        self::assertTrue($session->isValid($this->now));
    }

    #[Test]
    public function rejectsAnIncorrectPassword(): void
    {
        $result = $this->handler->handle(new AuthenticateAdminCommand('admin@gomrok.test', 'wrong', '127.0.0.1', null));

        self::assertTrue($result->isErr());
        self::assertSame('admin.invalid_credentials', $result->error()->code);
    }

    #[Test]
    public function rejectsAnUnknownEmailWithTheSameGenericError(): void
    {
        $result = $this->handler->handle(new AuthenticateAdminCommand('ghost@gomrok.test', 'anything', '127.0.0.1', null));

        self::assertTrue($result->isErr());
        self::assertSame('admin.invalid_credentials', $result->error()->code);
    }

    #[Test]
    public function rejectsADisabledAccountEvenWithTheCorrectPassword(): void
    {
        $user = AdminUser::create('Dana Disabled', 'dana@gomrok.test', password_hash('secret', \PASSWORD_DEFAULT), AdminRole::SupportAgent, $this->now);
        $user->setStatus(AdminUserStatus::Disabled, $this->now);
        $this->users->save($user);

        $result = $this->handler->handle(new AuthenticateAdminCommand('dana@gomrok.test', 'secret', '127.0.0.1', null));

        self::assertTrue($result->isErr());
        self::assertSame('admin.account_disabled', $result->error()->code);
    }

    #[Test]
    public function locksOutAfterTooManyFailedAttemptsWithinTheWindow(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->handler->handle(new AuthenticateAdminCommand('admin@gomrok.test', 'wrong', '127.0.0.1', null));
        }

        // The 5th failure already used up the lockout budget — even the
        // correct password is now rejected without checking it.
        $result = $this->handler->handle(new AuthenticateAdminCommand('admin@gomrok.test', 'correct-horse', '127.0.0.1', null));

        self::assertTrue($result->isErr());
        self::assertSame('admin.too_many_attempts', $result->error()->code);
    }

    #[Test]
    public function everyAttemptIsRecordedSuccessOrFailure(): void
    {
        $this->handler->handle(new AuthenticateAdminCommand('admin@gomrok.test', 'wrong', '127.0.0.1', null));
        $this->handler->handle(new AuthenticateAdminCommand('admin@gomrok.test', 'correct-horse', '127.0.0.1', null));

        $recorded = $this->attempts->all();
        self::assertCount(2, $recorded);
        self::assertFalse($recorded[0]->succeeded);
        self::assertTrue($recorded[1]->succeeded);
    }
}
