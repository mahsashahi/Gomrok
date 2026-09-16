<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use Gomrok\Shared\Application\ErrorLog\ErrorLogResolver;
use PDO;

final readonly class PdoErrorLogResolver implements ErrorLogResolver
{
    public function __construct(private PDO $pdo)
    {
    }

    public function markResolved(int $id, ?int $adminUserId, DateTimeImmutable $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE error_logs SET resolved_at = :resolved_at, resolved_by = :resolved_by WHERE id = :id',
        );
        $statement->execute([
            'resolved_at' => $now->format('Y-m-d H:i:s'),
            'resolved_by' => $adminUserId,
            'id' => $id,
        ]);

        return $statement->rowCount() > 0;
    }

    public function markUnresolved(int $id): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE error_logs SET resolved_at = NULL, resolved_by = NULL WHERE id = :id',
        );
        $statement->execute(['id' => $id]);

        return $statement->rowCount() > 0;
    }
}
