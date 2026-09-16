<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\SetAdminUserStatus;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\SetAdminUserStatus\SetAdminUserStatusCommand;
use Gomrok\Modules\Admin\Application\SetAdminUserStatus\SetAdminUserStatusHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserStatus;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SetAdminUserStatusHandlerTest extends TestCase
{
    private InMemoryAdminUserRepository $users;
    private SetAdminUserStatusHandler $handler;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $this->users = new InMemoryAdminUserRepository();

        $user = AdminUser::create('Jane Doe', 'jane@example.com', password_hash('x', \PASSWORD_DEFAULT), AdminRole::SupportAgent, $now);
        $this->users->save($user);
        $this->userId = $user->id() ?? 0;

        $other = AdminUser::create('Other Admin', 'other@example.com', password_hash('x', \PASSWORD_DEFAULT), AdminRole::Admin, $now);
        $this->users->save($other);
        $this->otherUserId = $other->id() ?? 0;

        $this->handler = new SetAdminUserStatusHandler(
            $this->users,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );
    }

    #[Test]
    public function disablesAnotherAdminUser(): void
    {
        $result = $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'disabled', actorId: $this->otherUserId));

        self::assertTrue($result->isOk());
        self::assertSame(AdminUserStatus::Disabled, $this->users->findById($this->userId)?->status());
    }

    #[Test]
    public function reactivatesADisabledUser(): void
    {
        $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'disabled', actorId: $this->otherUserId));

        $result = $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'active', actorId: $this->otherUserId));

        self::assertTrue($result->isOk());
        self::assertTrue($this->users->findById($this->userId)?->isUsable());
    }

    #[Test]
    public function refusesToDisableYourOwnAccount(): void
    {
        $result = $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'disabled', actorId: $this->userId));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.cannot_disable_self', $result->error()->code);
        self::assertTrue($this->users->findById($this->userId)?->isUsable());
    }

    #[Test]
    public function refusesToLockYourOwnAccount(): void
    {
        $result = $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'locked', actorId: $this->userId));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.cannot_disable_self', $result->error()->code);
    }

    #[Test]
    public function allowsActivatingYourOwnAccountAsAHarmlessNoOp(): void
    {
        $result = $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'active', actorId: $this->userId));

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function isIdempotent(): void
    {
        $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'disabled', actorId: $this->otherUserId));

        $result = $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'disabled', actorId: $this->otherUserId));

        self::assertTrue($result->isOk());
    }

    #[Test]
    public function rejectsAnUnknownStatus(): void
    {
        $result = $this->handler->handle(new SetAdminUserStatusCommand($this->userId, 'bogus', actorId: $this->otherUserId));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.unknown_status', $result->error()->code);
    }

    #[Test]
    public function rejectsAnUnknownUser(): void
    {
        $result = $this->handler->handle(new SetAdminUserStatusCommand(999, 'disabled', actorId: $this->otherUserId));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.not_found', $result->error()->code);
    }
}
