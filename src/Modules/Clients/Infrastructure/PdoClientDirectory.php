<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Infrastructure;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * Read-side {@see ClientDirectory}: a thin projection query, no aggregate
 * hydration. This is what other modules use to resolve a client.
 */
final readonly class PdoClientDirectory implements ClientDirectory
{
    private const COLUMNS = 'id, slug, name, status, default_currency, default_country, timezone';

    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?ClientSnapshot
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM clients WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findBySlug(string $slug): ?ClientSnapshot
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM clients WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);

        return $this->hydrate($statement->fetch());
    }

    public function existsById(int $id): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM clients WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);

        return $statement->fetchColumn() !== false;
    }

    private function hydrate(mixed $row): ?ClientSnapshot
    {
        if (!\is_array($row)) {
            return null;
        }

        return new ClientSnapshot(
            Row::int($row['id'] ?? null),
            Row::str($row['slug'] ?? ''),
            Row::str($row['name'] ?? ''),
            ClientStatus::from(Row::str($row['status'] ?? 'active')),
            Row::str($row['default_currency'] ?? ''),
            Row::nullableStr($row['default_country'] ?? null),
            Row::str($row['timezone'] ?? 'UTC'),
        );
    }
}
