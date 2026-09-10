<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see ClientApiKeyRepository}. Lookups are by the unique `key_id`
 * (the hot auth path).
 */
final readonly class PdoClientApiKeyRepository implements ClientApiKeyRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ClientApiKey $apiKey): void
    {
        if ($apiKey->id() === null) {
            $this->insert($apiKey);

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE client_api_keys SET
                status = :status, label = :label, last_used_at = :last_used_at,
                expires_at = :expires_at, revoked_at = :revoked_at, revoked_by = :revoked_by
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $apiKey->id(),
            'status' => $apiKey->status()->value,
            'label' => $apiKey->label(),
            'last_used_at' => $apiKey->lastUsedAt()?->format(self::DT),
            'expires_at' => $apiKey->expiresAt()?->format(self::DT),
            'revoked_at' => $apiKey->revokedAt()?->format(self::DT),
            'revoked_by' => $apiKey->revokedBy(),
        ]);
    }

    public function findByKeyId(string $keyId): ?ClientApiKey
    {
        $statement = $this->pdo->prepare('SELECT * FROM client_api_keys WHERE key_id = :key_id');
        $statement->execute(['key_id' => $keyId]);

        return $this->hydrate($statement->fetch());
    }

    public function touchLastUsed(int $id, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare('UPDATE client_api_keys SET last_used_at = :at WHERE id = :id');
        $statement->execute(['at' => $at->format(self::DT), 'id' => $id]);
    }

    public function findByClientId(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM client_api_keys WHERE client_id = :id ORDER BY id');
        $statement->execute(['id' => $clientId]);

        $keys = [];
        while (($row = $statement->fetch()) !== false) {
            $key = $this->hydrate($row);
            if ($key !== null) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function countActiveForClient(int $clientId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM client_api_keys WHERE client_id = :id AND status = :status',
        );
        $statement->execute(['id' => $clientId, 'status' => ApiKeyStatus::Active->value]);

        return Row::int($statement->fetchColumn());
    }

    private function insert(ClientApiKey $apiKey): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO client_api_keys
                (client_id, key_id, secret_hash, prefix, last_four, label, status,
                 created_at, last_used_at, expires_at, revoked_at, revoked_by)
             VALUES
                (:client_id, :key_id, :secret_hash, :prefix, :last_four, :label, :status,
                 :created_at, :last_used_at, :expires_at, :revoked_at, :revoked_by)',
        );
        $statement->execute([
            'client_id' => $apiKey->clientId(),
            'key_id' => $apiKey->keyId(),
            'secret_hash' => $apiKey->secretHash(),
            'prefix' => $apiKey->prefix()->value,
            'last_four' => $apiKey->lastFour(),
            'label' => $apiKey->label(),
            'status' => $apiKey->status()->value,
            'created_at' => $apiKey->createdAt()->format(self::DT),
            'last_used_at' => $apiKey->lastUsedAt()?->format(self::DT),
            'expires_at' => $apiKey->expiresAt()?->format(self::DT),
            'revoked_at' => $apiKey->revokedAt()?->format(self::DT),
            'revoked_by' => $apiKey->revokedBy(),
        ]);

        $apiKey->assignId((int) $this->pdo->lastInsertId());
    }

    private function hydrate(mixed $row): ?ClientApiKey
    {
        if (!\is_array($row)) {
            return null;
        }

        return ClientApiKey::fromRow([
            'id' => Row::int($row['id'] ?? null),
            'client_id' => Row::int($row['client_id'] ?? null),
            'key_id' => Row::str($row['key_id'] ?? ''),
            'secret_hash' => Row::str($row['secret_hash'] ?? ''),
            'prefix' => Row::str($row['prefix'] ?? 'gk_live'),
            'last_four' => Row::str($row['last_four'] ?? ''),
            'label' => Row::nullableStr($row['label'] ?? null),
            'status' => Row::str($row['status'] ?? 'active'),
            'created_at' => Row::str($row['created_at'] ?? 'now'),
            'last_used_at' => Row::nullableStr($row['last_used_at'] ?? null),
            'expires_at' => Row::nullableStr($row['expires_at'] ?? null),
            'revoked_at' => Row::nullableStr($row['revoked_at'] ?? null),
            'revoked_by' => Row::nullableInt($row['revoked_by'] ?? null),
        ]);
    }
}
