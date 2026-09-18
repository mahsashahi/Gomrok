<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application\EnqueueClientNotification;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Application\NotificationEndpointResolver;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;

/**
 * Turns "this payment/subscription just reached a notify-worthy status"
 * (already filtered by {@see \Gomrok\Modules\Notifications\Domain\NotifyWorthyStatuses}
 * in the calling subscriber) into a queued {@see ClientNotification} row.
 * Deliberately does no network I/O — this runs synchronously inside the
 * dispatcher, itself called right after the triggering transaction commits,
 * so it must stay fast (CLAUDE.md: never block the request on outbound
 * delivery). Actual delivery is the retry/delivery job's job.
 */
final readonly class EnqueueClientNotificationHandler
{
    public function __construct(
        private NotificationEndpointResolver $endpoints,
        private ClientNotificationRepository $notifications,
    ) {
    }

    public function handle(
        int $clientId,
        NotificationTargetType $targetType,
        int $targetId,
        EndpointPurpose $purpose,
        string $statusValue,
        ?int $providerAccountId,
        DateTimeImmutable $occurredAt,
    ): void {
        $url = $this->endpoints->resolve($clientId, $providerAccountId, $purpose);
        if ($url === null) {
            // No endpoint configured for this client/purpose — a client
            // configuration gap, not a Gomrok failure. Nothing to enqueue.
            return;
        }

        $payload = json_encode([
            'type' => $purpose->value,
            'id' => $targetId,
            'status' => $statusValue,
            'occurred_at' => $occurredAt->format(DATE_ATOM),
        ], \JSON_THROW_ON_ERROR);

        $notification = ClientNotification::enqueue(
            $clientId,
            $targetType,
            $targetId,
            $purpose->value,
            $statusValue,
            $providerAccountId,
            $url,
            $payload,
            $occurredAt,
        );

        $this->notifications->save($notification);
    }
}
