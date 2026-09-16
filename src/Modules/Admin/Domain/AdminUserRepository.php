<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

interface AdminUserRepository
{
    public function save(AdminUser $user): void;

    public function findById(int $id): ?AdminUser;

    public function findByEmail(string $email): ?AdminUser;

    /**
     * @return list<AdminUser>
     */
    public function all(): array;
}
