<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Domain\AdminLoginAttempt;
use Gomrok\Modules\Admin\Domain\AdminLoginAttemptRepository;
use Gomrok\Modules\Admin\Domain\AdminUser;
use PDO;

final readonly class PdoAdminLoginAttemptRepository implements AdminLoginAttemptRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(AdminLoginAttempt $attempt): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_login_attempts (email, admin_user_id, ip, succeeded, created_at)
             VALUES (:email, :admin_user_id, :ip, :succeeded, :created_at)',
        );
        $statement->execute([
            'email' => $attempt->email,
            'admin_user_id' => $attempt->adminUserId,
            'ip' => $attempt->ip,
            'succeeded' => $attempt->succeeded ? 1 : 0,
            'created_at' => $attempt->createdAt->format(self::DT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function countFailedSince(string $email, DateTimeImmutable $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM admin_login_attempts WHERE email = :email AND succeeded = 0 AND created_at >= :since',
        );
        $statement->execute(['email' => AdminUser::normaliseEmail($email), 'since' => $since->format(self::DT)]);

        return (int) $statement->fetchColumn();
    }
}
