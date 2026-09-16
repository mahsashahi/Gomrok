<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\AdminUsers;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\AdminUsers\AdminUsersScreenHandler;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Tests\Support\InMemoryAdminUserRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminUsersScreenHandlerTest extends TestCase
{
    #[Test]
    public function listsEveryAdminUserAndFlagsTheCurrentOne(): void
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $users = new InMemoryAdminUserRepository();

        $a = AdminUser::create('Alice', 'alice@example.com', 'hash', AdminRole::Admin, $now);
        $users->save($a);
        $b = AdminUser::create('Bob', 'bob@example.com', 'hash', AdminRole::SupportAgent, $now);
        $users->save($b);

        $handler = new AdminUsersScreenHandler($users);
        $result = $handler->build($a->id() ?? 0);

        self::assertCount(2, $result->rows);

        $byName = [];
        foreach ($result->rows as $row) {
            $byName[$row->name] = $row;
        }

        self::assertTrue($byName['Alice']->isSelf);
        self::assertFalse($byName['Bob']->isSelf);
        self::assertSame('admin', $byName['Alice']->role);
        self::assertSame('support_agent', $byName['Bob']->role);
        self::assertSame('active', $byName['Alice']->status);
        self::assertSame('2026-09-16', $byName['Alice']->createdLabel);
    }

    #[Test]
    public function noAdminUsersMeansAnEmptyList(): void
    {
        $handler = new AdminUsersScreenHandler(new InMemoryAdminUserRepository());

        $result = $handler->build(1);

        self::assertSame([], $result->rows);
    }
}
