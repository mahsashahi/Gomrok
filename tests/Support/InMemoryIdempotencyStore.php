<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Shared\Application\Idempotency\IdempotencyRecord;
use Gomrok\Shared\Application\Idempotency\IdempotencyStatus;
use Gomrok\Shared\Application\Idempotency\IdempotencyStore;

/**
 * In-memory {@see IdempotencyStore} with the same claim/reset/expiry semantics
 * as {@see \Gomrok\Shared\Infrastructure\Persistence\PdoIdempotencyStore}, for
 * exercising middleware and job logic without MySQL.
 *
 * @phpstan-type Row array{status: IdempotencyStatus, fingerprint: string, targetType: ?string, targetId: ?int, responseStatus: ?int, expiresAt: DateTimeImmutable}
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string, Row> */
    private array $rows = [];

    public function claim(
        int $clientId,
        string $key,
        string $requestFingerprint,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): ?IdempotencyRecord {
        $id = $this->id($clientId, $key);
        $row = $this->rows[$id] ?? null;

        if ($row === null || $row['expiresAt'] <= $now || $row['status'] === IdempotencyStatus::Failed) {
            $this->rows[$id] = [
                'status' => IdempotencyStatus::Processing,
                'fingerprint' => $requestFingerprint,
                'targetType' => null,
                'targetId' => null,
                'responseStatus' => null,
                'expiresAt' => $expiresAt,
            ];

            return null;
        }

        return new IdempotencyRecord(
            $row['status'],
            $row['fingerprint'],
            $row['targetType'],
            $row['targetId'],
            $row['responseStatus'],
        );
    }

    public function markCompleted(
        int $clientId,
        string $key,
        ?string $targetType,
        ?int $targetId,
        int $responseStatus,
        DateTimeImmutable $now,
    ): void {
        $id = $this->id($clientId, $key);
        if (!isset($this->rows[$id])) {
            return;
        }

        $this->rows[$id]['status'] = IdempotencyStatus::Done;
        $this->rows[$id]['targetType'] = $targetType;
        $this->rows[$id]['targetId'] = $targetId;
        $this->rows[$id]['responseStatus'] = $responseStatus;
    }

    public function markFailed(int $clientId, string $key, DateTimeImmutable $now): void
    {
        $id = $this->id($clientId, $key);
        if (isset($this->rows[$id])) {
            $this->rows[$id]['status'] = IdempotencyStatus::Failed;
        }
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $before = \count($this->rows);

        $this->rows = array_filter($this->rows, static fn (array $row): bool => $row['expiresAt'] > $now);

        return $before - \count($this->rows);
    }

    private function id(int $clientId, string $key): string
    {
        return $clientId . "\0" . $key;
    }
}
