<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

/**
 * Persistence port for {@see ClientNotification}.
 */
interface ClientNotificationRepository
{
    public function save(ClientNotification $notification): void;

    public function findById(int $id): ?ClientNotification;

    /**
     * Rows the cron delivery/retry job should attempt: `pending` with
     * `next_attempt_at` at or before now.
     *
     * @return list<ClientNotification>
     */
    public function findDeliverable(int $limit): array;
}
