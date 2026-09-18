<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application\Subscribers;

use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Application\EnqueueClientNotification\EnqueueClientNotificationHandler;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Modules\Notifications\Domain\NotifyWorthyStatuses;
use Gomrok\Modules\Subscriptions\Domain\Events\SubscriptionStatusChanged;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Shared\Application\Events\DomainEventSubscriber;
use Gomrok\Shared\Domain\DomainEvent;
use LogicException;

final readonly class EnqueueOnSubscriptionStatusChanged implements DomainEventSubscriber
{
    public function __construct(
        private EnqueueClientNotificationHandler $enqueue,
    ) {
    }

    public function subscribesTo(): array
    {
        return [SubscriptionStatusChanged::class];
    }

    public function handle(DomainEvent $event): void
    {
        if (!$event instanceof SubscriptionStatusChanged) {
            throw new LogicException(self::class . ' only handles ' . SubscriptionStatusChanged::class);
        }

        $status = SubscriptionStatus::from($event->toStatus);
        if (!NotifyWorthyStatuses::subscription($status)) {
            return;
        }

        $this->enqueue->handle(
            $event->clientId,
            NotificationTargetType::Subscription,
            $event->subscriptionId,
            EndpointPurpose::SubscriptionStatus,
            $event->toStatus,
            $event->providerAccountId,
            $event->occurredAt(),
        );
    }
}
