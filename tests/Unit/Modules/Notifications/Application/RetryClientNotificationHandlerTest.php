<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Application;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Application\ClientNotificationSendOutcome;
use Gomrok\Modules\Notifications\Application\DeliverClientNotification\DeliverClientNotificationHandler;
use Gomrok\Modules\Notifications\Application\RetryClientNotification\RetryClientNotificationCommand;
use Gomrok\Modules\Notifications\Application\RetryClientNotification\RetryClientNotificationHandler;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Modules\Notifications\Infrastructure\HmacNotificationSigner;
use Gomrok\Tests\Support\FakeClientNotificationSecret;
use Gomrok\Tests\Support\FakeClientNotificationSender;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientNotificationRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryClientNotificationHandlerTest extends TestCase
{
    #[Test]
    public function retryingADeadLetteredRowAttemptsDeliveryImmediately(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', 7, 'https://acme.example/hook', '{}', $now);
        $notification->recordDeadLetter($now, 500, null, 'HTTP 500');
        $notifications->save($notification);
        $id = $notification->id();
        \assert($id !== null);

        $secrets = new FakeClientNotificationSecret();
        $secrets->set(1, 'client-secret');
        $sender = new FakeClientNotificationSender();
        $sender->queue(ClientNotificationSendOutcome::delivered(200, 'ok'));
        $deliverer = new DeliverClientNotificationHandler($notifications, $secrets, new HmacNotificationSigner(), $sender, new FrozenClock($now->format(DATE_ATOM)));

        $audit = new RecordingAuditLogWriter();
        $handler = new RetryClientNotificationHandler($notifications, $deliverer, $audit, new FrozenClock($now->format(DATE_ATOM)));

        $result = $handler->handle(new RetryClientNotificationCommand($id, actorId: 3));

        self::assertTrue($result->isOk());
        self::assertSame(ClientNotificationStatus::Sent, $notification->status());
        self::assertCount(1, $sender->calls);
        self::assertNotEmpty($audit->entries);
    }

    #[Test]
    public function aPendingRowCannotBeManuallyRetried(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', 7, 'https://acme.example/hook', '{}', $now);
        $notifications->save($notification);
        $id = $notification->id();
        \assert($id !== null);

        $secrets = new FakeClientNotificationSecret();
        $deliverer = new DeliverClientNotificationHandler($notifications, $secrets, new HmacNotificationSigner(), new FakeClientNotificationSender(), new FrozenClock($now->format(DATE_ATOM)));
        $handler = new RetryClientNotificationHandler($notifications, $deliverer, new RecordingAuditLogWriter(), new FrozenClock($now->format(DATE_ATOM)));

        $result = $handler->handle(new RetryClientNotificationCommand($id));

        self::assertTrue($result->isErr());
        self::assertSame('client_notification.not_dead_lettered', $result->error()->code);
    }

    #[Test]
    public function anUnknownIdIsNotFound(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $deliverer = new DeliverClientNotificationHandler($notifications, new FakeClientNotificationSecret(), new HmacNotificationSigner(), new FakeClientNotificationSender(), new FrozenClock($now->format(DATE_ATOM)));
        $handler = new RetryClientNotificationHandler($notifications, $deliverer, new RecordingAuditLogWriter(), new FrozenClock($now->format(DATE_ATOM)));

        $result = $handler->handle(new RetryClientNotificationCommand(999));

        self::assertTrue($result->isErr());
        self::assertSame('client_notification.not_found', $result->error()->code);
    }
}
