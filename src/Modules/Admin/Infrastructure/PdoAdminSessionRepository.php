<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Domain\AdminSession;
use Gomrok\Modules\Admin\Domain\AdminSessionRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoAdminSessionRepository implements AdminSessionRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(AdminSession $session): void
    {
        if ($session->id() === null) {
            $this->insert($session);

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE admin_sessions SET revoked_at = :revoked_at, last_used_at = :last_used_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $session->id(),
            'revoked_at' => $session->revokedAt()?->format(self::DT),
            'last_used_at' => $session->lastUsedAt()?->format(self::DT),
        ]);
    }

    public function findByTokenHash(string $tokenHash): ?AdminSession
    {
        $statement = $this->pdo->prepare('SELECT * FROM admin_sessions WHERE token_hash = :h');
        $statement->execute(['h' => $tokenHash]);

        return $this->hydrate($statement->fetch());
    }

    private function hydrate(mixed $row): ?AdminSession
    {
        if (!\is_array($row)) {
            return null;
        }

        $revokedAt = Row::nullableStr($row['revoked_at'] ?? null);
        $lastUsedAt = Row::nullableStr($row['last_used_at'] ?? null);

        return AdminSession::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['admin_user_id'] ?? null),
            Row::str($row['token_hash'] ?? ''),
            Row::nullableStr($row['ip'] ?? null),
            Row::nullableStr($row['user_agent'] ?? null),
            new DateTimeImmutable(Row::str($row['expires_at'] ?? null)),
            $revokedAt !== null ? new DateTimeImmutable($revokedAt) : null,
            new DateTimeImmutable(Row::str($row['created_at'] ?? null)),
            $lastUsedAt !== null ? new DateTimeImmutable($lastUsedAt) : null,
        );
    }

    private function insert(AdminSession $session): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO admin_sessions (admin_user_id, token_hash, ip, user_agent, expires_at, revoked_at, created_at, last_used_at)
             VALUES (:admin_user_id, :token_hash, :ip, :user_agent, :expires_at, :revoked_at, :created_at, :last_used_at)',
        );
        $statement->execute([
            'admin_user_id' => $session->adminUserId(),
            'token_hash' => $session->tokenHash(),
            'ip' => $session->ip(),
            'user_agent' => $session->userAgent(),
            'expires_at' => $session->expiresAt()->format(self::DT),
            'revoked_at' => $session->revokedAt()?->format(self::DT),
            'created_at' => $session->createdAt()->format(self::DT),
            'last_used_at' => $session->lastUsedAt()?->format(self::DT),
        ]);

        $session->assignId((int) $this->pdo->lastInsertId());
    }
}
