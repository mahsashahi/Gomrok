<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application;

use Gomrok\Modules\Notifications\Domain\ClientNotification;

/**
 * Published read API over `client_notification_logs` — the admin
 * Notifications screen's only reader, mirroring {@see
 * \Gomrok\Shared\Application\ErrorLog\ErrorLogDirectory}'s write-port/
 * read-port split.
 */
interface ClientNotificationDirectory
{
    public function find(int $id): ?ClientNotification;

    /**
     * Newest first.
     *
     * @return list<ClientNotification>
     */
    public function search(ClientNotificationFilter $filter): array;

    /** Total rows matching the filter, ignoring `limit`/`offset` — for pagination. */
    public function countMatching(ClientNotificationFilter $filter): int;

    /** Count of `dead_lettered` rows, for the screen's summary/badge. */
    public function countDeadLettered(?int $clientId = null): int;
}
