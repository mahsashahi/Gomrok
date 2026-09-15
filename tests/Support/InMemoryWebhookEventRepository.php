<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Modules\Webhooks\Domain\WebhookEventRepository;
use Gomrok\Modules\Webhooks\Domain\WebhookEventStatus;

final class InMemoryWebhookEventRepository implements WebhookEventRepository
{
    /** @var array<int, WebhookEvent> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(WebhookEvent $event): void
    {
        if ($event->id() === null) {
            $event->assignId($this->nextId++);
        }
        $id = $event->id();
        \assert($id !== null);
        $this->byId[$id] = $event;
    }

    public function findById(int $id): ?WebhookEvent
    {
        return $this->byId[$id] ?? null;
    }

    public function findByDedupKey(int $providerAccountId, string $eventId, string $rawStatus): ?WebhookEvent
    {
        foreach ($this->byId as $event) {
            if (
                $event->providerAccountId() === $providerAccountId
                && $event->eventId() === $eventId
                && $event->rawStatus() === $rawStatus
            ) {
                return $event;
            }
        }

        return null;
    }

    public function findRetryable(int $limit, int $staleAfterMinutes = 10): array
    {
        $retryable = array_values(array_filter(
            $this->byId,
            static fn (WebhookEvent $e): bool => $e->status() === WebhookEventStatus::Received
                || $e->status() === WebhookEventStatus::RetryPending,
        ));

        return \array_slice($retryable, 0, $limit);
    }

    public function count(): int
    {
        return \count($this->byId);
    }
}
