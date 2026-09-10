<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientSlug;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Shared\Domain\CountryCode;
use Gomrok\Shared\Domain\Currency;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private DateTimeImmutable $t0;

    protected function setUp(): void
    {
        $this->t0 = new DateTimeImmutable('2026-09-08T12:00:00+00:00');
    }

    #[Test]
    public function registersActiveWithNoEndpoints(): void
    {
        $client = $this->register();

        self::assertTrue($client->isActive());
        self::assertSame(ClientStatus::Active, $client->status());
        self::assertSame([], $client->endpoints());
        self::assertNull($client->updatedAt());
    }

    #[Test]
    public function disableThenEnableIsReversibleAndIdempotent(): void
    {
        $client = $this->register();
        $t1 = $this->t0->modify('+1 hour');

        $client->disable(5, 'fraud review', $t1);
        self::assertFalse($client->isActive());
        self::assertSame('fraud review', $client->disabledReason());
        self::assertSame(5, $client->disabledBy());

        // idempotent — a second disable does not overwrite the first stamp
        $client->disable(9, 'other', $this->t0->modify('+2 hours'));
        self::assertSame(5, $client->disabledBy());

        $client->enable($this->t0->modify('+3 hours'));
        self::assertTrue($client->isActive());
        self::assertNull($client->disabledAt());
        self::assertNull($client->disabledReason());
    }

    #[Test]
    public function setEndpointUpsertsPerPurpose(): void
    {
        $client = $this->register();

        $client->setEndpoint(EndpointPurpose::PaymentStatus, 'https://a.example/hook', $this->t0);
        $client->setEndpoint(EndpointPurpose::PaymentStatus, 'https://b.example/hook', $this->t0);
        $client->setEndpoint(EndpointPurpose::RefundStatus, 'https://a.example/refunds', $this->t0);

        self::assertCount(2, $client->endpoints());

        $payment = null;
        foreach ($client->endpoints() as $endpoint) {
            if ($endpoint->purpose() === EndpointPurpose::PaymentStatus) {
                $payment = $endpoint;
            }
        }
        self::assertNotNull($payment);
        self::assertSame('https://b.example/hook', $payment->url());

        $client->removeEndpoint(EndpointPurpose::PaymentStatus, $this->t0);
        self::assertCount(1, $client->endpoints());
    }

    #[Test]
    public function renameStampsUpdatedAt(): void
    {
        $client = $this->register();
        $t1 = $this->t0->modify('+5 minutes');

        $client->rename('New Name', $t1);

        self::assertSame('New Name', $client->name());
        self::assertEquals($t1, $client->updatedAt());
    }

    private function register(): Client
    {
        return Client::register(
            ClientSlug::of('televika'),
            'Televika',
            Currency::of('EUR'),
            CountryCode::of('DE'),
            'Europe/Berlin',
            'signing-secret',
            $this->t0,
        );
    }
}
