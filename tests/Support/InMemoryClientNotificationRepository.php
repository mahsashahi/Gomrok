<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;

final class InMemoryClientNotificationRepository implements ClientNotificationRepository
{
    /** @var array<int, ClientNotification> */
    private array $byId = [];

    private int $nextId = 1;

    /** Fixed reference time for {@see findDeliverable()}; defaults to the real clock. */
    private ?\DateTimeImmutable $now = null;

    public function setNow(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    public function save(ClientNotification $notification): void
    {
        if ($notification->id() === null) {
            $notification->assignId($this->nextId++);
        }
        $id = $notification->id();
        \assert($id !== null);
        $this->byId[$id] = $notification;
    }

    public function findById(int $id): ?ClientNotification
    {
        return $this->byId[$id] ?? null;
    }

    public function findDeliverable(int $limit): array
    {
        $now = $this->now ?? new \DateTimeImmutable();
        $deliverable = array_values(array_filter(
            $this->byId,
            static fn (ClientNotification $n): bool => $n->status() === ClientNotificationStatus::Pending
                && $n->nextAttemptAt() !== null && $n->nextAttemptAt() <= $now,
        ));

        usort($deliverable, static fn (ClientNotification $a, ClientNotification $b): int => ($a->nextAttemptAt() <=> $b->nextAttemptAt()));

        return \array_slice($deliverable, 0, $limit);
    }

    /**
     * @return list<ClientNotification>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
