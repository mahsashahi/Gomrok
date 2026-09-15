<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Modules\Webhooks\Domain\WebhookEventRepository;
use Gomrok\Modules\Webhooks\Domain\WebhookEventStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoWebhookEventRepository implements WebhookEventRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(WebhookEvent $event): void
    {
        if ($event->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO webhook_events
                    (client_id, provider_account_id, provider_type_code, event_id, event_type, raw_status,
                     provider_reference, raw_payload, headers, status, attempt_count, error_code, error_message,
                     payment_id, processed_at, last_attempted_at, created_at, updated_at)
                 VALUES
                    (:client_id, :provider_account_id, :provider_type_code, :event_id, :event_type, :raw_status,
                     :provider_reference, :raw_payload, :headers, :status, :attempt_count, :error_code, :error_message,
                     :payment_id, :processed_at, :last_attempted_at, :created_at, :updated_at)',
            );
            $statement->execute($this->bindings($event));
            $event->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE webhook_events SET
                status = :status, attempt_count = :attempt_count, error_code = :error_code,
                error_message = :error_message, payment_id = :payment_id, processed_at = :processed_at,
                last_attempted_at = :last_attempted_at, updated_at = :updated_at
             WHERE id = :id',
        );
        $bindings = $this->bindings($event);
        $statement->execute([
            'id' => $event->id(),
            'status' => $bindings['status'],
            'attempt_count' => $bindings['attempt_count'],
            'error_code' => $bindings['error_code'],
            'error_message' => $bindings['error_message'],
            'payment_id' => $bindings['payment_id'],
            'processed_at' => $bindings['processed_at'],
            'last_attempted_at' => $bindings['last_attempted_at'],
            'updated_at' => $bindings['updated_at'],
        ]);
    }

    public function findById(int $id): ?WebhookEvent
    {
        $statement = $this->pdo->prepare('SELECT * FROM webhook_events WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByDedupKey(int $providerAccountId, string $eventId, string $rawStatus): ?WebhookEvent
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM webhook_events WHERE provider_account_id = :a AND event_id = :e AND raw_status = :s',
        );
        $statement->execute(['a' => $providerAccountId, 'e' => $eventId, 's' => $rawStatus]);

        return $this->hydrate($statement->fetch());
    }

    public function findRetryable(int $limit, int $staleAfterMinutes = 10): array
    {
        $staleBefore = gmdate(self::DT, time() - $staleAfterMinutes * 60);
        $statement = $this->pdo->prepare(
            "SELECT * FROM webhook_events
              WHERE status IN ('received', 'retry_pending')
                 OR (status = 'processing' AND updated_at < :stale_before)
              ORDER BY created_at ASC
              LIMIT :limit",
        );
        $statement->bindValue('stale_before', $staleBefore);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $events = [];
        while (($row = $statement->fetch()) !== false) {
            $event = $this->hydrate($row);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return array<string, mixed>
     */
    private function bindings(WebhookEvent $event): array
    {
        return [
            'client_id' => $event->clientId(),
            'provider_account_id' => $event->providerAccountId(),
            'provider_type_code' => $event->providerTypeCode(),
            'event_id' => $event->eventId(),
            'event_type' => $event->eventType(),
            'raw_status' => $event->rawStatus(),
            'provider_reference' => $event->providerReference(),
            'raw_payload' => $event->rawPayload(),
            'headers' => json_encode($event->headers(), JSON_THROW_ON_ERROR),
            'status' => $event->status()->value,
            'attempt_count' => $event->attemptCount(),
            'error_code' => $event->errorCode(),
            'error_message' => $event->errorMessage(),
            'payment_id' => $event->paymentId(),
            'processed_at' => $event->processedAt()?->format(self::DT),
            'last_attempted_at' => $event->lastAttemptedAt()?->format(self::DT),
            'created_at' => $event->createdAt()->format(self::DT),
            'updated_at' => $event->updatedAt()?->format(self::DT),
        ];
    }

    private function hydrate(mixed $row): ?WebhookEvent
    {
        if (!\is_array($row)) {
            return null;
        }

        $headersRaw = $row['headers'] ?? null;
        $headersDecoded = \is_string($headersRaw) ? json_decode($headersRaw, true) : null;
        /** @var array<string, string> $headers */
        $headers = \is_array($headersDecoded) ? $headersDecoded : [];

        $processedAt = Row::nullableStr($row['processed_at'] ?? null);
        $lastAttemptedAt = Row::nullableStr($row['last_attempted_at'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return WebhookEvent::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::str($row['provider_type_code'] ?? ''),
            Row::nullableStr($row['event_id'] ?? null),
            Row::nullableStr($row['event_type'] ?? null),
            Row::nullableStr($row['raw_status'] ?? null),
            Row::nullableStr($row['provider_reference'] ?? null),
            Row::str($row['raw_payload'] ?? ''),
            $headers,
            WebhookEventStatus::from(Row::str($row['status'] ?? 'received')),
            Row::int($row['attempt_count'] ?? null),
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            Row::nullableInt($row['payment_id'] ?? null),
            $processedAt !== null ? new DateTimeImmutable($processedAt) : null,
            $lastAttemptedAt !== null ? new DateTimeImmutable($lastAttemptedAt) : null,
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
