<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Notifications\Application\DeliverClientNotification\DeliverClientNotificationHandler;
use Gomrok\Modules\Notifications\Application\Jobs\NotificationRetryScanHandler;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Modules\Notifications\Infrastructure\HmacNotificationSigner;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FakeClientNotificationSecret;
use Gomrok\Tests\Support\FakeClientNotificationSender;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientNotificationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationRetryScanHandlerTest extends TestCase
{
    private InMemoryClientNotificationRepository $notifications;
    private FakeClientNotificationSecret $secrets;
    private FakeClientNotificationSender $sender;
    private FrozenClock $clock;
    private NotificationRetryScanHandler $handler;

    protected function setUp(): void
    {
        $this->notifications = new InMemoryClientNotificationRepository();
        $this->secrets = new FakeClientNotificationSecret();
        $this->sender = new FakeClientNotificationSender();
        $this->clock = new FrozenClock('2026-09-17T12:00:00+00:00');

        $deliverer = new DeliverClientNotificationHandler(
            $this->notifications,
            $this->secrets,
            new HmacNotificationSigner(),
            $this->sender,
            $this->clock,
        );

        $this->handler = new NotificationRetryScanHandler($this->notifications, $deliverer);
    }

    #[Test]
    public function exposesItsTypeAndRecurrence(): void
    {
        self::assertSame('notification_retry_scan', $this->handler->type());
        self::assertSame(1, $this->handler->recurrenceIntervalMinutes());
    }

    #[Test]
    public function deliversEveryDueNotificationAndTalliesAttempted(): void
    {
        $this->secrets->set(1, 'shh');
        $due = ClientNotification::enqueue(1, NotificationTargetType::Payment, 1, 'payment_status', 'paid', null, 'https://acme.example/a', '{}', new DateTimeImmutable());
        $this->notifications->save($due);

        $job = Job::schedule('notification_retry_scan', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertTrue($result->success);
        self::assertSame(['attempted' => 1], $result->summary);
        self::assertCount(1, $this->sender->calls);

        $reloaded = $this->notifications->findById($due->id() ?? 0);
        self::assertNotNull($reloaded);
        self::assertSame('sent', $reloaded->status()->value);
    }

    #[Test]
    public function reportsZeroAttemptedWhenNothingIsDue(): void
    {
        $job = Job::schedule('notification_retry_scan', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertSame(['attempted' => 0], $result->summary);
    }
}
