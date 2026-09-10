<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Gomrok\Shared\Application\Idempotency\IdempotencyRecord;
use Gomrok\Shared\Application\Idempotency\IdempotencyStatus;
use Gomrok\Shared\Application\Idempotency\IdempotencyStore;
use PDO;
use PDOException;

/**
 * MySQL-backed {@see IdempotencyStore}. Concurrency safety rests on the
 * `uniq_idempotency_keys_client_key` unique index plus row locking: `claim()`
 * runs in a transaction ({@see TransactionRunner}), locks any existing row
 * `FOR UPDATE`, and either resets it (expired / failed) or reports it back.
 */
final readonly class PdoIdempotencyStore implements IdempotencyStore
{
    private const SQL_DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private PDO $pdo,
        private TransactionRunner $transactions,
    ) {
    }

    public function claim(
        int $clientId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): ?IdempotencyRecord {
        try {
            return $this->transactions->run(function () use ($clientId, $key, $requestFingerprint, $now, $expiresAt): ?IdempotencyRecord {
                $existing = $this->lockExisting($clientId, $key);

                if ($existing === null) {
                    $this->insertProcessing($clientId, $key, $requestFingerprint, $now, $expiresAt);

                    return null;
                }

                return $this->resolveExisting($existing, $clientId, $key, $requestFingerprint, $now, $expiresAt);
            });
        } catch (PDOException $e) {
            // Lost the insert race — another request claimed the key first.
            if ($this->isUniqueViolation($e)) {
                return $this->readRecord($clientId, $key)
                    ?? new IdempotencyRecord(IdempotencyStatus::Processing, $requestFingerprint, null, null, null);
            }

            throw $e;
        }
    }

    public function markCompleted(
        int $clientId,
        string $key,
        ?string $targetType,
        ?int $targetId,
        int $responseStatus,
        DateTimeImmutable $now,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE idempotency_keys
                SET status = :status, target_type = :target_type, target_id = :target_id,
                    response_status = :response_status, updated_at = :now
              WHERE client_id = :client_id AND idempotency_key = :key',
        );
        $statement->execute([
            'status' => IdempotencyStatus::Done->value,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'response_status' => $responseStatus,
            'now' => $now->format(self::SQL_DATETIME),
            'client_id' => $clientId,
            'key' => $key,
        ]);
    }

    public function markFailed(int $clientId, string $key, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE idempotency_keys
                SET status = :status, updated_at = :now
              WHERE client_id = :client_id AND idempotency_key = :key',
        );
        $statement->execute([
            'status' => IdempotencyStatus::Failed->value,
            'now' => $now->format(self::SQL_DATETIME),
            'client_id' => $clientId,
            'key' => $key,
        ]);
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $statement = $this->pdo->prepare('DELETE FROM idempotency_keys WHERE expires_at <= :now');
        $statement->execute(['now' => $now->format(self::SQL_DATETIME)]);

        return $statement->rowCount();
    }

    /**
     * @return array{status: string, request_fingerprint: string, target_type: string|null, target_id: string|null, response_status: string|null, expires_at: string}|null
     */
    private function lockExisting(int $clientId, string $key): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT status, request_fingerprint, target_type, target_id, response_status, expires_at
               FROM idempotency_keys
              WHERE client_id = :client_id AND idempotency_key = :key
                FOR UPDATE',
        );
        $statement->execute(['client_id' => $clientId, 'key' => $key]);
        $row = $statement->fetch();

        if (!\is_array($row)) {
            return null;
        }

        return [
            'status' => self::str($row['status'] ?? ''),
            'request_fingerprint' => self::str($row['request_fingerprint'] ?? ''),
            'target_type' => self::nullableStr($row['target_type'] ?? null),
            'target_id' => self::nullableStr($row['target_id'] ?? null),
            'response_status' => self::nullableStr($row['response_status'] ?? null),
            'expires_at' => self::str($row['expires_at'] ?? ''),
        ];
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(mixed $value): ?string
    {
        return $value === null ? null : self::str($value);
    }

    /**
     * @param array{status: string, request_fingerprint: string, target_type: string|null, target_id: string|null, response_status: string|null, expires_at: string} $existing
     */
    private function resolveExisting(
        array $existing,
        int $clientId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): ?IdempotencyRecord {
        $status = IdempotencyStatus::from($existing['status']);
        $expired = new DateTimeImmutable($existing['expires_at'], new DateTimeZone('UTC')) <= $now;

        if ($expired || $status === IdempotencyStatus::Failed) {
            $this->resetToProcessing($clientId, $key, $requestFingerprint, $now, $expiresAt);

            return null;
        }

        return new IdempotencyRecord(
            $status,
            $existing['request_fingerprint'],
            $existing['target_type'],
            $existing['target_id'] !== null ? (int) $existing['target_id'] : null,
            $existing['response_status'] !== null ? (int) $existing['response_status'] : null,
        );
    }

    private function insertProcessing(
        int $clientId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO idempotency_keys
                (client_id, idempotency_key, request_fingerprint, status, created_at, updated_at, expires_at)
             VALUES (:client_id, :key, :fingerprint, :status, :now, :now, :expires_at)',
        );
        $statement->execute([
            'client_id' => $clientId,
            'key' => $key,
            'fingerprint' => $requestFingerprint,
            'status' => IdempotencyStatus::Processing->value,
            'now' => $now->format(self::SQL_DATETIME),
            'expires_at' => $expiresAt->format(self::SQL_DATETIME),
        ]);
    }

    private function resetToProcessing(
        int $clientId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE idempotency_keys
                SET status = :status, request_fingerprint = :fingerprint,
                    target_type = NULL, target_id = NULL, response_status = NULL,
                    created_at = :now, updated_at = :now, expires_at = :expires_at
              WHERE client_id = :client_id AND idempotency_key = :key',
        );
        $statement->execute([
            'status' => IdempotencyStatus::Processing->value,
            'fingerprint' => $requestFingerprint,
            'now' => $now->format(self::SQL_DATETIME),
            'expires_at' => $expiresAt->format(self::SQL_DATETIME),
            'client_id' => $clientId,
            'key' => $key,
        ]);
    }

    private function readRecord(int $clientId, string $key): ?IdempotencyRecord
    {
        $statement = $this->pdo->prepare(
            'SELECT status, request_fingerprint, target_type, target_id, response_status
               FROM idempotency_keys
              WHERE client_id = :client_id AND idempotency_key = :key',
        );
        $statement->execute(['client_id' => $clientId, 'key' => $key]);
        $row = $statement->fetch();

        if (!\is_array($row)) {
            return null;
        }

        $targetId = self::nullableStr($row['target_id'] ?? null);
        $responseStatus = self::nullableStr($row['response_status'] ?? null);

        return new IdempotencyRecord(
            IdempotencyStatus::from(self::str($row['status'] ?? '')),
            self::str($row['request_fingerprint'] ?? ''),
            self::nullableStr($row['target_type'] ?? null),
            $targetId !== null ? (int) $targetId : null,
            $responseStatus !== null ? (int) $responseStatus : null,
        );
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        return $e->errorInfo !== null && ($e->errorInfo[1] ?? null) === 1062;
    }
}
