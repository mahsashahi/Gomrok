<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Jobs;

use DateTimeImmutable;
use Gomrok\Jobs\RetryPendingClientNotifications;
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
use Psr\Log\NullLogger;

final class RetryPendingClientNotificationsTest extends TestCase
{
    #[Test]
    public function onlyDueRowsAreAttempted(): void
    {
        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $notifications = new InMemoryClientNotificationRepository();
        $notifications->setNow($now);

        $due = ClientNotification::enqueue(1, NotificationTargetType::Payment, 1, 'payment_status', 'paid', null, 'https://acme.example/a', '{}', $now);
        $notifications->save($due);

        $notDueYet = ClientNotification::enqueue(1, NotificationTargetType::Payment, 2, 'payment_status', 'paid', null, 'https://acme.example/b', '{}', $now);
        $notDueYet->recordRetry($now, 500, null, 'HTTP 500', $now->modify('+1 hour'));
        $notifications->save($notDueYet);

        $alreadySent = ClientNotification::enqueue(1, NotificationTargetType::Payment, 3, 'payment_status', 'paid', null, 'https://acme.example/c', '{}', $now);
        $alreadySent->recordSuccess($now, 200, 'ok');
        $notifications->save($alreadySent);

        $secrets = new FakeClientNotificationSecret();
        $secrets->set(1, 'secret');
        $sender = new FakeClientNotificationSender();
        $sender->queue(ClientNotificationSendOutcome::delivered(200, 'ok'));
        $deliverer = new DeliverClientNotificationHandler($notifications, $secrets, new HmacNotificationSigner(), $sender, new FrozenClock($now->format(DATE_ATOM)));

        $job = new RetryPendingClientNotifications($notifications, $deliverer, new NullLogger());
        $summary = $job();

        self::assertSame(1, $summary['attempted']);
        self::assertSame(ClientNotificationStatus::Sent, $due->status());
        self::assertSame(ClientNotificationStatus::Pending, $notDueYet->status());
    }
}
