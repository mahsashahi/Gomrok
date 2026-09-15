<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEvent;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEventRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoSubscriptionEventRepository implements SubscriptionEventRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(SubscriptionEvent $event): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO subscription_events (subscription_id, kind, provider_status_raw, payload, created_at)
             VALUES (:subscription_id, :kind, :provider_status_raw, :payload, :now)',
        );
        $statement->execute([
            'subscription_id' => $event->subscriptionId,
            'kind' => $event->kind,
            'provider_status_raw' => $event->providerStatusRaw,
            'payload' => $event->payload !== null ? json_encode($event->payload, JSON_THROW_ON_ERROR) : null,
            'now' => $event->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function forSubscription(int $subscriptionId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM subscription_events WHERE subscription_id = :s ORDER BY id ASC');
        $statement->execute(['s' => $subscriptionId]);

        $events = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $events[] = $this->hydrate($row);
            }
        }

        return $events;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): SubscriptionEvent
    {
        $payloadRaw = $row['payload'] ?? null;
        $payloadDecoded = \is_string($payloadRaw) ? json_decode($payloadRaw, true) : null;

        return new SubscriptionEvent(
            Row::int($row['id'] ?? null),
            Row::int($row['subscription_id'] ?? null),
            Row::str($row['kind'] ?? ''),
            Row::nullableStr($row['provider_status_raw'] ?? null),
            \is_array($payloadDecoded) ? $payloadDecoded : null,
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
