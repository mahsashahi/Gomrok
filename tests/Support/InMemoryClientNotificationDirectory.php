<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Notifications\Application\ClientNotificationDirectory;
use Gomrok\Modules\Notifications\Application\ClientNotificationFilter;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;

final class InMemoryClientNotificationDirectory implements ClientNotificationDirectory
{
    /** @var list<ClientNotification> */
    private array $rows = [];

    public function add(ClientNotification $notification): void
    {
        $this->rows[] = $notification;
    }

    public function find(int $id): ?ClientNotification
    {
        foreach ($this->rows as $row) {
            if ($row->id() === $id) {
                return $row;
            }
        }

        return null;
    }

    public function search(ClientNotificationFilter $filter): array
    {
        $matching = array_values(array_filter($this->rows, fn (ClientNotification $n): bool => $this->matches($n, $filter)));
        usort($matching, static fn (ClientNotification $a, ClientNotification $b): int => ($b->id() ?? 0) <=> ($a->id() ?? 0));

        return \array_slice($matching, $filter->offset, $filter->limit);
    }

    public function countMatching(ClientNotificationFilter $filter): int
    {
        return \count(array_filter($this->rows, fn (ClientNotification $n): bool => $this->matches($n, $filter)));
    }

    public function countDeadLettered(?int $clientId = null): int
    {
        return \count(array_filter(
            $this->rows,
            static fn (ClientNotification $n): bool => $n->status() === ClientNotificationStatus::DeadLettered
                && ($clientId === null || $n->clientId() === $clientId),
        ));
    }

    private function matches(ClientNotification $n, ClientNotificationFilter $filter): bool
    {
        if ($filter->clientId !== null && $n->clientId() !== $filter->clientId) {
            return false;
        }
        if ($filter->status !== null && $n->status()->value !== $filter->status) {
            return false;
        }
        if ($filter->purpose !== null && $n->purpose() !== $filter->purpose) {
            return false;
        }

        return true;
    }
}
