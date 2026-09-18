<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Notifications\Application;

use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Application\NotificationEndpointResolver;
use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverride;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryProviderAccountNotificationOverrideRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 28 Q1 (user-specified hybrid): a provider account's own override
 * wins when present; otherwise the client's `client_endpoints` default;
 * neither configured is `null` (skip, not an error).
 */
final class NotificationEndpointResolverTest extends TestCase
{
    #[Test]
    public function fallsBackToTheClientDefaultWhenNoOverrideExists(): void
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $clients->setEndpoint(1, EndpointPurpose::PaymentStatus, 'https://acme.example/default');

        $resolver = new NotificationEndpointResolver($clients, new InMemoryProviderAccountNotificationOverrideRepository());

        self::assertSame('https://acme.example/default', $resolver->resolve(1, 7, EndpointPurpose::PaymentStatus));
    }

    #[Test]
    public function anActiveOverrideForTheProducingAccountWins(): void
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $clients->setEndpoint(1, EndpointPurpose::PaymentStatus, 'https://acme.example/default');

        $overrides = new InMemoryProviderAccountNotificationOverrideRepository();
        $overrides->save(ProviderAccountNotificationOverride::register(7, 'payment_status', 'https://acme.example/stripe-only'));

        $resolver = new NotificationEndpointResolver($clients, $overrides);

        self::assertSame('https://acme.example/stripe-only', $resolver->resolve(1, 7, EndpointPurpose::PaymentStatus));
    }

    #[Test]
    public function noProviderAccountMeansOnlyTheClientDefaultIsConsidered(): void
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $clients->setEndpoint(1, EndpointPurpose::PaymentStatus, 'https://acme.example/default');

        $resolver = new NotificationEndpointResolver($clients, new InMemoryProviderAccountNotificationOverrideRepository());

        self::assertSame('https://acme.example/default', $resolver->resolve(1, null, EndpointPurpose::PaymentStatus));
    }

    #[Test]
    public function neitherConfiguredResolvesToNull(): void
    {
        $clients = new InMemoryClientDirectory();
        $clients->add(new ClientSnapshot(1, 'acme', 'Acme', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $resolver = new NotificationEndpointResolver($clients, new InMemoryProviderAccountNotificationOverrideRepository());

        self::assertNull($resolver->resolve(1, 7, EndpointPurpose::PaymentStatus));
    }
}
