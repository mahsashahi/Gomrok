<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Domain\AdminRole;
use Gomrok\Modules\Admin\Domain\AdminUser;
use Gomrok\Modules\Admin\Domain\AdminUserRepository;
use Gomrok\Modules\Admin\Domain\AdminUserStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoAdminUserRepository implements AdminUserRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(AdminUser $user): void
    {
        if ($user->id() === null) {
            $this->insert($user);

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE admin_users SET
                name = :name, password_hash = :password_hash, status = :status, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $user->id(),
            'name' => $user->name(),
            'password_hash' => $user->passwordHash(),
            'status' => $user->status()->value,
            'updated_at' => ($user->updatedAt() ?? new DateTimeImmutable())->format(self::DT),
        ]);
    }

    public function findById(int $id): ?AdminUser
    {
        $statement = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByEmail(string $email): ?AdminUser
    {
        $statement = $this->pdo->prepare('SELECT * FROM admin_users WHERE email = :email');
        $statement->execute(['email' => AdminUser::normaliseEmail($email)]);

        return $this->hydrate($statement->fetch());
    }

    public function all(): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM admin_users ORDER BY name');
        $statement->execute();

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            $user = $this->hydrate($row);
            if ($user !== null) {
                $out[] = $user;
            }
        }

        return $out;
    }

    private function insert(AdminUser $user): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_users (name, email, password_hash, role, status, created_at, updated_at)
             VALUES (:name, :email, :password_hash, :role, :status, :created_at, :updated_at)',
        );
        $statement->execute([
            'name' => $user->name(),
            'email' => $user->email(),
            'password_hash' => $user->passwordHash(),
            'role' => $user->role()->value,
            'status' => $user->status()->value,
            'created_at' => $user->createdAt()->format(self::DT),
            'updated_at' => $user->updatedAt()?->format(self::DT),
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $user->assignId($id);
    }

    private function hydrate(mixed $row): ?AdminUser
    {
        if (!\is_array($row)) {
            return null;
        }

        $createdAt = Row::str($row['created_at'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return AdminUser::fromStorage(
            Row::int($row['id'] ?? null),
            Row::str($row['name'] ?? ''),
            Row::str($row['email'] ?? ''),
            Row::str($row['password_hash'] ?? ''),
            AdminRole::from(Row::str($row['role'] ?? 'support_agent')),
            AdminUserStatus::from(Row::str($row['status'] ?? 'active')),
            new DateTimeImmutable($createdAt),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
