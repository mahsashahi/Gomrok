<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\ResetAdminUserPassword;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\ResetAdminUserPassword\ResetAdminUserPasswordCommand;
use Gomrok\Modules\Admin\Application\ResetAdminUserPassword\ResetAdminUserPasswordHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResetAdminUserPasswordHandlerTest extends TestCase
{
    private InMemoryAdminUserRepository $users;
    private RecordingAuditLogWriter $audit;
    private ResetAdminUserPasswordHandler $handler;
    private int $userId;

    protected function setUp(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $this->users = new InMemoryAdminUserRepository();

        $user = AdminUser::create('Jane Doe', 'jane@example.com', password_hash('old-password', \PASSWORD_DEFAULT), AdminRole::SupportAgent, $now);
        $this->users->save($user);
        $this->userId = $user->id() ?? 0;

        $this->audit = new RecordingAuditLogWriter();
        $this->handler = new ResetAdminUserPasswordHandler(
            $this->users,
            $this->audit,
            new SynchronousTransactions(),
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );
    }

    #[Test]
    public function replacesThePasswordHash(): void
    {
        $result = $this->handler->handle(new ResetAdminUserPasswordCommand($this->userId, 'a brand new password', actorId: 99));

        self::assertTrue($result->isOk());
        $user = $this->users->findById($this->userId);
        self::assertNotNull($user);
        self::assertTrue($user->matchesPassword('a brand new password'));
        self::assertFalse($user->matchesPassword('old-password'));
        self::assertContains('admin_user.password_reset', $this->audit->actions());
    }

    #[Test]
    public function neverRecordsThePlaintextPasswordInTheAuditEntry(): void
    {
        $this->handler->handle(new ResetAdminUserPasswordCommand($this->userId, 'a brand new password', actorId: 99));

        foreach ($this->audit->entries as $entry) {
            $encoded = json_encode([$entry->before, $entry->after, $entry->context]);
            self::assertIsString($encoded);
            self::assertStringNotContainsString('a brand new password', $encoded);
        }
    }

    #[Test]
    public function rejectsATooShortPassword(): void
    {
        $result = $this->handler->handle(new ResetAdminUserPasswordCommand($this->userId, 'short', actorId: 99));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.password_too_short', $result->error()->code);
    }

    #[Test]
    public function rejectsAnUnknownUser(): void
    {
        $result = $this->handler->handle(new ResetAdminUserPasswordCommand(999, 'a brand new password', actorId: 99));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.not_found', $result->error()->code);
    }
}
