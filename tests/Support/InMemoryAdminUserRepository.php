<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;

final class InMemoryAdminUserRepository implements AdminUserRepository
{
    /** @var array<int, AdminUser> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(AdminUser $user): void
    {
        if ($user->id() === null) {
            $user->assignId($this->nextId++);
        }
        $id = $user->id();
        \assert($id !== null);
        $this->byId[$id] = $user;
    }

    public function findById(int $id): ?AdminUser
    {
        return $this->byId[$id] ?? null;
    }

    public function findByEmail(string $email): ?AdminUser
    {
        $normalised = AdminUser::normaliseEmail($email);
        foreach ($this->byId as $user) {
            if ($user->email() === $normalised) {
                return $user;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->byId);
    }
}
