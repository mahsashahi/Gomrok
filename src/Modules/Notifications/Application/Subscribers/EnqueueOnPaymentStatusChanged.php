<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application\Subscribers;

use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Application\EnqueueClientNotification\EnqueueClientNotificationHandler;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Modules\Notifications\Domain\NotifyWorthyStatuses;
use Gomrok\Modules\Payments\Domain\Events\PaymentStatusChanged;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Shared\Application\Events\DomainEventSubscriber;
use Gomrok\Shared\Domain\DomainEvent;
use LogicException;

/**
 * Refund statuses (`refunded`/`partially_refunded`) route through
 * `EndpointPurpose::PaymentStatus` here too, not the older
 * `EndpointPurpose::RefundStatus` (Phase 6) — they're still `PaymentStatus`
 * values, not a separate aggregate, so one integration point covers a
 * client's whole payment lifecycle. `RefundStatus` stays defined but unused.
 */
final readonly class EnqueueOnPaymentStatusChanged implements DomainEventSubscriber
{
    public function __construct(
        private EnqueueClientNotificationHandler $enqueue,
    ) {
    }

    public function subscribesTo(): array
    {
        return [PaymentStatusChanged::class];
    }

    public function handle(DomainEvent $event): void
    {
        if (!$event instanceof PaymentStatusChanged) {
            throw new LogicException(self::class . ' only handles ' . PaymentStatusChanged::class);
        }

        $status = PaymentStatus::from($event->toStatus);
        if (!NotifyWorthyStatuses::payment($status)) {
            return;
        }

        $this->enqueue->handle(
            $event->clientId,
            NotificationTargetType::Payment,
            $event->paymentId,
            EndpointPurpose::PaymentStatus,
            $event->toStatus,
            $event->providerAccountId,
            $event->occurredAt(),
        );
    }
}
