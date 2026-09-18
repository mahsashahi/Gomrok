<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoClientNotificationRepository implements ClientNotificationRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ClientNotification $notification): void
    {
        if ($notification->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO client_notification_logs
                    (client_id, target_type, target_id, purpose, status_value, provider_account_id,
                     endpoint_url, payload, status, attempt_count, next_attempt_at, last_attempted_at,
                     last_response_status, last_response_body, last_error, created_at, updated_at)
                 VALUES
                    (:client_id, :target_type, :target_id, :purpose, :status_value, :provider_account_id,
                     :endpoint_url, :payload, :status, :attempt_count, :next_attempt_at, :last_attempted_at,
                     :last_response_status, :last_response_body, :last_error, :created_at, :updated_at)',
            );
            $statement->execute($this->bindings($notification));
            $notification->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE client_notification_logs SET
                status = :status, attempt_count = :attempt_count, next_attempt_at = :next_attempt_at,
                last_attempted_at = :last_attempted_at, last_response_status = :last_response_status,
                last_response_body = :last_response_body, last_error = :last_error, updated_at = :updated_at
             WHERE id = :id',
        );
        $bindings = $this->bindings($notification);
        $statement->execute([
            'id' => $notification->id(),
            'status' => $bindings['status'],
            'attempt_count' => $bindings['attempt_count'],
            'next_attempt_at' => $bindings['next_attempt_at'],
            'last_attempted_at' => $bindings['last_attempted_at'],
            'last_response_status' => $bindings['last_response_status'],
            'last_response_body' => $bindings['last_response_body'],
            'last_error' => $bindings['last_error'],
            'updated_at' => $bindings['updated_at'],
        ]);
    }

    public function findById(int $id): ?ClientNotification
    {
        $statement = $this->pdo->prepare('SELECT * FROM client_notification_logs WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findDeliverable(int $limit): array
    {
        $statement = $this->pdo->prepare(
            "SELECT * FROM client_notification_logs
              WHERE status = 'pending' AND next_attempt_at <= :now
              ORDER BY next_attempt_at ASC
              LIMIT :limit",
        );
        $statement->bindValue('now', gmdate(self::DT));
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $notifications = [];
        while (($row = $statement->fetch()) !== false) {
            $notification = $this->hydrate($row);
            if ($notification !== null) {
                $notifications[] = $notification;
            }
        }

        return $notifications;
    }

    /**
     * @return array<string, mixed>
     */
    private function bindings(ClientNotification $notification): array
    {
        return [
            'client_id' => $notification->clientId(),
            'target_type' => $notification->targetType()->value,
            'target_id' => $notification->targetId(),
            'purpose' => $notification->purpose(),
            'status_value' => $notification->statusValue(),
            'provider_account_id' => $notification->providerAccountId(),
            'endpoint_url' => $notification->endpointUrl(),
            'payload' => $notification->payload(),
            'status' => $notification->status()->value,
            'attempt_count' => $notification->attemptCount(),
            'next_attempt_at' => $notification->nextAttemptAt()?->format(self::DT),
            'last_attempted_at' => $notification->lastAttemptedAt()?->format(self::DT),
            'last_response_status' => $notification->lastResponseStatus(),
            'last_response_body' => $notification->lastResponseBody(),
            'last_error' => $notification->lastError(),
            'created_at' => $notification->createdAt()->format(self::DT),
            'updated_at' => $notification->updatedAt()?->format(self::DT),
        ];
    }

    private function hydrate(mixed $row): ?ClientNotification
    {
        if (!\is_array($row)) {
            return null;
        }

        $nextAttemptAt = Row::nullableStr($row['next_attempt_at'] ?? null);
        $lastAttemptedAt = Row::nullableStr($row['last_attempted_at'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return ClientNotification::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            NotificationTargetType::from(Row::str($row['target_type'] ?? 'payment')),
            Row::int($row['target_id'] ?? null),
            Row::str($row['purpose'] ?? ''),
            Row::str($row['status_value'] ?? ''),
            Row::nullableInt($row['provider_account_id'] ?? null),
            Row::str($row['endpoint_url'] ?? ''),
            Row::str($row['payload'] ?? ''),
            ClientNotificationStatus::from(Row::str($row['status'] ?? 'pending')),
            Row::int($row['attempt_count'] ?? null),
            $nextAttemptAt !== null ? new DateTimeImmutable($nextAttemptAt) : null,
            $lastAttemptedAt !== null ? new DateTimeImmutable($lastAttemptedAt) : null,
            Row::nullableInt($row['last_response_status'] ?? null),
            Row::nullableStr($row['last_response_body'] ?? null),
            Row::nullableStr($row['last_error'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
