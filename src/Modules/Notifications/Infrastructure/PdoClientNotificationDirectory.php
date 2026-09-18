<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Application\ClientNotificationDirectory;
use Gomrok\Modules\Notifications\Application\ClientNotificationFilter;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoClientNotificationDirectory implements ClientNotificationDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function find(int $id): ?ClientNotification
    {
        $statement = $this->pdo->prepare('SELECT * FROM client_notification_logs WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function search(ClientNotificationFilter $filter): array
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare(
            "SELECT * FROM client_notification_logs {$where} ORDER BY id DESC LIMIT :limit OFFSET :offset",
        );
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', max(1, min(200, $filter->limit)), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $filter->offset), PDO::PARAM_INT);
        $statement->execute();

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    public function countMatching(ClientNotificationFilter $filter): int
    {
        [$where, $params] = $this->whereClause($filter);

        $statement = $this->pdo->prepare("SELECT COUNT(*) FROM client_notification_logs {$where}");
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    public function countDeadLettered(?int $clientId = null): int
    {
        $sql = "SELECT COUNT(*) FROM client_notification_logs WHERE status = 'dead_lettered'";
        $params = [];
        if ($clientId !== null) {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $clientId;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, scalar>}
     */
    private function whereClause(ClientNotificationFilter $filter): array
    {
        $conditions = [];
        $params = [];

        if ($filter->clientId !== null) {
            $conditions[] = 'client_id = :client_id';
            $params['client_id'] = $filter->clientId;
        }
        if ($filter->status !== null) {
            $conditions[] = 'status = :status';
            $params['status'] = $filter->status;
        }
        if ($filter->purpose !== null) {
            $conditions[] = 'purpose = :purpose';
            $params['purpose'] = $filter->purpose;
        }

        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $params];
    }

    private function hydrate(mixed $row): ClientNotification
    {
        \assert(\is_array($row));

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
