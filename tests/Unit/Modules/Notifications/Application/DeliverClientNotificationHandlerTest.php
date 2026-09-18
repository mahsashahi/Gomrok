<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Application;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Application\ClientNotificationSendOutcome;
use Gomrok\Modules\Notifications\Application\DeliverClientNotification\DeliverClientNotificationHandler;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationStatus;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Modules\Notifications\Infrastructure\HmacNotificationSigner;
use Gomrok\Tests\Support\FakeClientNotificationSecret;
use Gomrok\Tests\Support\FakeClientNotificationSender;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientNotificationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DeliverClientNotificationHandlerTest extends TestCase
{
    private function notification(DateTimeImmutable $now, int $priorFailedAttempts = 0): ClientNotification
    {
        $notification = ClientNotification::enqueue(1, NotificationTargetType::Payment, 42, 'payment_status', 'paid', 7, 'https://acme.example/hook', '{"status":"paid"}', $now);
        for ($i = 0; $i < $priorFailedAttempts; ++$i) {
            $notification->recordRetry($now, 500, null, 'HTTP 500', $now);
        }

        return $notification;
    }

    #[Test]
    public function aSuccessfulDeliveryMarksItSent(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $secrets = new FakeClientNotificationSecret();
        $secrets->set(1, 'client-secret');
        $sender = new FakeClientNotificationSender();
        $sender->queue(ClientNotificationSendOutcome::delivered(200, 'ok'));

        $handler = new DeliverClientNotificationHandler($notifications, $secrets, new HmacNotificationSigner(), $sender, new FrozenClock($now->format(DATE_ATOM)));
        $notification = $this->notification($now);
        $notifications->save($notification);

        $handler->deliver($notification);

        self::assertSame(ClientNotificationStatus::Sent, $notification->status());
        self::assertCount(1, $sender->calls);
        self::assertSame('https://acme.example/hook', $sender->calls[0]['url']);
        self::assertStringStartsWith('t=', $sender->calls[0]['signature']);
    }

    #[Test]
    public function aFailureBeforeExhaustionStaysPendingWithBackoff(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $secrets = new FakeClientNotificationSecret();
        $secrets->set(1, 'client-secret');
        $sender = new FakeClientNotificationSender();
        $sender->queue(ClientNotificationSendOutcome::rejected(500, 'server error'));

        $handler = new DeliverClientNotificationHandler($notifications, $secrets, new HmacNotificationSigner(), $sender, new FrozenClock($now->format(DATE_ATOM)));
        $notification = $this->notification($now);
        $notifications->save($notification);

        $handler->deliver($notification);

        self::assertSame(ClientNotificationStatus::Pending, $notification->status());
        self::assertSame(1, $notification->attemptCount());
        self::assertNotNull($notification->nextAttemptAt());
        self::assertGreaterThan($now, $notification->nextAttemptAt());
    }

    #[Test]
    public function theEighthFailureDeadLetters(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $secrets = new FakeClientNotificationSecret();
        $secrets->set(1, 'client-secret');
        $sender = new FakeClientNotificationSender();
        $sender->queue(ClientNotificationSendOutcome::unreachable('connection refused'));

        $handler = new DeliverClientNotificationHandler($notifications, $secrets, new HmacNotificationSigner(), $sender, new FrozenClock($now->format(DATE_ATOM)));
        $notification = $this->notification($now, priorFailedAttempts: 7);
        $notifications->save($notification);

        $handler->deliver($notification);

        self::assertSame(ClientNotificationStatus::DeadLettered, $notification->status());
        self::assertSame(8, $notification->attemptCount());
        self::assertNull($notification->nextAttemptAt());
    }

    #[Test]
    public function noSigningSecretDeadLettersImmediatelyWithoutCallingTheSender(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $sender = new FakeClientNotificationSender();

        $handler = new DeliverClientNotificationHandler($notifications, new FakeClientNotificationSecret(), new HmacNotificationSigner(), $sender, new FrozenClock($now->format(DATE_ATOM)));
        $notification = $this->notification($now);
        $notifications->save($notification);

        $handler->deliver($notification);

        self::assertSame(ClientNotificationStatus::DeadLettered, $notification->status());
        self::assertCount(0, $sender->calls);
    }
}
