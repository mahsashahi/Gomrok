<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Application;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Application\EnqueueClientNotification\EnqueueClientNotificationHandler;
use Gomrok\Modules\Notifications\Application\NotificationEndpointResolver;
use Gomrok\Modules\Notifications\Domain\NotificationTargetType;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryClientNotificationRepository;
use Gomrok\Tests\Support\InMemoryProviderAccountNotificationOverrideRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnqueueClientNotificationHandlerTest extends TestCase
{
    #[Test]
    public function enqueuesASnapshotOfTheResolvedUrlAndPayload(): void
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $clients->setEndpoint(1, EndpointPurpose::PaymentStatus, 'https://acme.example/hook');

        $notifications = new InMemoryClientNotificationRepository();
        $handler = new EnqueueClientNotificationHandler(
            new NotificationEndpointResolver($clients, new InMemoryProviderAccountNotificationOverrideRepository()),
            $notifications,
        );

        $now = new DateTimeImmutable('2026-09-17T12:00:00+00:00');
        $handler->handle(1, NotificationTargetType::Payment, 42, EndpointPurpose::PaymentStatus, 'paid', 7, $now);

        $all = $notifications->all();
        self::assertCount(1, $all);
        $row = $all[0];
        self::assertSame(1, $row->clientId());
        self::assertSame(NotificationTargetType::Payment, $row->targetType());
        self::assertSame(42, $row->targetId());
        self::assertSame('payment_status', $row->purpose());
        self::assertSame('paid', $row->statusValue());
        self::assertSame(7, $row->providerAccountId());
        self::assertSame('https://acme.example/hook', $row->endpointUrl());

        $payload = json_decode($row->payload(), true);
        \assert(\is_array($payload));
        self::assertSame('payment_status', $payload['type']);
        self::assertSame(42, $payload['id']);
        self::assertSame('paid', $payload['status']);
    }

    #[Test]
    public function skipsEnqueuingWhenNoEndpointIsConfigured(): void
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $notifications = new InMemoryClientNotificationRepository();
        $handler = new EnqueueClientNotificationHandler(
            new NotificationEndpointResolver($clients, new InMemoryProviderAccountNotificationOverrideRepository()),
            $notifications,
        );

        $handler->handle(1, NotificationTargetType::Payment, 42, EndpointPurpose::PaymentStatus, 'paid', null, new DateTimeImmutable());

        self::assertCount(0, $notifications->all());
    }
}
