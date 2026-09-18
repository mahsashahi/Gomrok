<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Application\Subscribers;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Application\EnqueueClientNotification\EnqueueClientNotificationHandler;
use Gomrok\Modules\Notifications\Application\NotificationEndpointResolver;
use Gomrok\Modules\Notifications\Application\Subscribers\EnqueueOnPaymentStatusChanged;
use Gomrok\Modules\Payments\Domain\Events\PaymentStatusChanged;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientNotificationRepository;
use Gomrok\Tests\Support\InMemoryProviderAccountNotificationOverrideRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnqueueOnPaymentStatusChangedTest extends TestCase
{
    private function subscriber(InMemoryClientNotificationRepository $notifications): EnqueueOnPaymentStatusChanged
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $clients->setEndpoint(1, EndpointPurpose::PaymentStatus, 'https://acme.example/hook');

        return new EnqueueOnPaymentStatusChanged(new EnqueueClientNotificationHandler(
            new NotificationEndpointResolver($clients, new InMemoryProviderAccountNotificationOverrideRepository()),
            $notifications,
        ));
    }

    #[Test]
    public function enqueuesForANotifyWorthyStatus(): void
    {
        $notifications = new InMemoryClientNotificationRepository();
        $subscriber = $this->subscriber($notifications);

        $subscriber->handle(new PaymentStatusChanged(42, 1, 'authorized', 'paid', 7, new DateTimeImmutable()));

        self::assertCount(1, $notifications->all());
        self::assertSame('paid', $notifications->all()[0]->statusValue());
    }

    #[Test]
    public function skipsAMidFlowStatus(): void
    {
        $notifications = new InMemoryClientNotificationRepository();
        $subscriber = $this->subscriber($notifications);

        $subscriber->handle(new PaymentStatusChanged(42, 1, 'created', 'pending', 7, new DateTimeImmutable()));

        self::assertCount(0, $notifications->all());
    }

    #[Test]
    public function declaresItSubscribesToPaymentStatusChanged(): void
    {
        $subscriber = $this->subscriber(new InMemoryClientNotificationRepository());

        self::assertSame([PaymentStatusChanged::class], $subscriber->subscribesTo());
    }
}
