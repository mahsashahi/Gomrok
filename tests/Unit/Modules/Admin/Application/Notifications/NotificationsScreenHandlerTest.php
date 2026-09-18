<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Notifications;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Notifications\NotificationsFilterState;
use Gomrok\Modules\Admin\Application\Notifications\NotificationsScreenHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientNotificationDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationsScreenHandlerTest extends TestCase
{
    #[Test]
    public function buildsRowsWithClientLabelsAndRetryEligibility(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');

        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $notifications = new InMemoryClientNotificationDirectory();

        $sent = ClientNotification::enqueue(1, NotificationTargetType::Payment, 1, 'payment_status', 'paid', null, 'https://acme.example/a', '{}', $now);
        $sent->assignId(1);
        $sent->recordSuccess($now, 200, 'ok');
        $notifications->add($sent);

        $deadLettered = ClientNotification::enqueue(1, NotificationTargetType::Subscription, 2, 'subscription_status', 'past_due', null, 'https://acme.example/b', '{}', $now);
        $deadLettered->assignId(2);
        $deadLettered->recordDeadLetter($now, 500, null, 'HTTP 500');
        $notifications->add($deadLettered);

        $handler = new NotificationsScreenHandler($notifications, $clients);
        $result = $handler->build(new NotificationsFilterState(), 1);

        self::assertSame(2, $result->totalCount);
        self::assertSame(1, $result->deadLetteredCount);

        $rowsById = [];
        foreach ($result->rows as $row) {
            $rowsById[$row->id] = $row;
        }
        self::assertSame('Acme', $rowsById[1]->clientLabel);
        self::assertFalse($rowsById[1]->isRetryable);
        self::assertTrue($rowsById[2]->isRetryable);
    }

    #[Test]
    public function filtersByStatus(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $notifications = new InMemoryClientNotificationDirectory();
        $pending = ClientNotification::enqueue(1, NotificationTargetType::Payment, 1, 'payment_status', 'paid', null, 'https://acme.example/a', '{}', $now);
        $pending->assignId(1);
        $notifications->add($pending);

        $sent = ClientNotification::enqueue(1, NotificationTargetType::Payment, 2, 'payment_status', 'paid', null, 'https://acme.example/b', '{}', $now);
        $sent->assignId(2);
        $sent->recordSuccess($now, 200, 'ok');
        $notifications->add($sent);

        $handler = new NotificationsScreenHandler($notifications, $clients);
        $result = $handler->build(new NotificationsFilterState(status: 'sent'), 1);

        self::assertSame(1, $result->totalCount);
        self::assertSame('sent', $result->rows[0]->status);
    }
}
