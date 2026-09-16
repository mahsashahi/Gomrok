<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\CreateAdminUser;

use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserCommand;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserHandler;
use Gomrok\Modules\Admin\Application\CreateAdminUser\CreateAdminUserResult;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateAdminUserHandlerTest extends TestCase
{
    private InMemoryAdminUserRepository $users;
    private RecordingAuditLogWriter $audit;
    private CreateAdminUserHandler $handler;

    protected function setUp(): void
    {
        $this->users = new InMemoryAdminUserRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->handler = new CreateAdminUserHandler(
            $this->users,
            $this->audit,
            new SynchronousTransactions(),
            new FrozenClock('2026-09-16T12:00:00+00:00'),
        );
    }

    private function command(
        string $name = 'Jane Doe',
        string $email = 'jane@example.com',
        string $password = 'correct horse battery',
        string $role = 'support_agent',
    ): CreateAdminUserCommand {
        return new CreateAdminUserCommand($name, $email, $password, $role, actorId: 1);
    }

    #[Test]
    public function createsAnAdminUserWithAHashedPassword(): void
    {
        $result = $this->handler->handle($this->command());

        self::assertTrue($result->isOk());
        $payload = $result->value();
        self::assertInstanceOf(CreateAdminUserResult::class, $payload);

        $user = $this->users->findById($payload->adminUserId);
        self::assertNotNull($user);
        self::assertSame('Jane Doe', $user->name());
        self::assertSame('jane@example.com', $user->email());
        self::assertSame(AdminRole::SupportAgent, $user->role());
        self::assertTrue($user->matchesPassword('correct horse battery'));
        self::assertNotSame('correct horse battery', $user->passwordHash());
        self::assertContains('admin_user.created', $this->audit->actions());
    }

    #[Test]
    public function rejectsAnEmptyName(): void
    {
        $result = $this->handler->handle($this->command(name: '  '));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.name_required', $result->error()->code);
    }

    #[Test]
    public function rejectsAnInvalidEmail(): void
    {
        $result = $this->handler->handle($this->command(email: 'not-an-email'));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.invalid_email', $result->error()->code);
    }

    #[Test]
    public function rejectsATooShortPassword(): void
    {
        $result = $this->handler->handle($this->command(password: 'short'));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.password_too_short', $result->error()->code);
    }

    #[Test]
    public function rejectsAnUnknownRole(): void
    {
        $result = $this->handler->handle($this->command(role: 'superadmin'));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.unknown_role', $result->error()->code);
    }

    #[Test]
    public function rejectsADuplicateEmailCaseInsensitively(): void
    {
        $this->handler->handle($this->command(email: 'jane@example.com'));

        $result = $this->handler->handle($this->command(email: 'JANE@EXAMPLE.COM'));

        self::assertTrue($result->isErr());
        self::assertSame('admin_user.email_taken', $result->error()->code);
    }
}
