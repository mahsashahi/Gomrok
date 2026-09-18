<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Application\Subscribers;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Application\EnqueueClientNotification\EnqueueClientNotificationHandler;
use Gomrok\Modules\Notifications\Application\NotificationEndpointResolver;
use Gomrok\Modules\Notifications\Application\Subscribers\EnqueueOnSubscriptionStatusChanged;
use Gomrok\Modules\Subscriptions\Domain\Events\SubscriptionStatusChanged;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientNotificationRepository;
use Gomrok\Tests\Support\InMemoryProviderAccountNotificationOverrideRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnqueueOnSubscriptionStatusChangedTest extends TestCase
{
    private function subscriber(InMemoryClientNotificationRepository $notifications): EnqueueOnSubscriptionStatusChanged
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $clients->setEndpoint(1, EndpointPurpose::SubscriptionStatus, 'https://acme.example/sub-hook');

        return new EnqueueOnSubscriptionStatusChanged(new EnqueueClientNotificationHandler(
            new NotificationEndpointResolver($clients, new InMemoryProviderAccountNotificationOverrideRepository()),
            $notifications,
        ));
    }

    #[Test]
    public function enqueuesForANotifyWorthyStatus(): void
    {
        $notifications = new InMemoryClientNotificationRepository();
        $subscriber = $this->subscriber($notifications);

        $subscriber->handle(new SubscriptionStatusChanged(9, 1, 'trialing', 'active', 7, new DateTimeImmutable()));

        self::assertCount(1, $notifications->all());
        self::assertSame('active', $notifications->all()[0]->statusValue());
        self::assertSame('subscription_status', $notifications->all()[0]->purpose());
    }

    #[Test]
    public function skipsTrialing(): void
    {
        $notifications = new InMemoryClientNotificationRepository();
        $subscriber = $this->subscriber($notifications);

        $subscriber->handle(new SubscriptionStatusChanged(9, 1, 'active', 'trialing', 7, new DateTimeImmutable()));

        self::assertCount(0, $notifications->all());
    }
}
