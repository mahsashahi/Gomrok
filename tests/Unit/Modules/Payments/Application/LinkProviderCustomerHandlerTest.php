<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Payments\Application;

use Gomrok\Modules\Payments\Application\LinkProviderCustomer\LinkProviderCustomerCommand;
use Gomrok\Modules\Payments\Application\LinkProviderCustomer\LinkProviderCustomerHandler;
use Gomrok\Modules\Payments\Application\LinkProviderCustomer\LinkProviderCustomerResult;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryProviderCustomerRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LinkProviderCustomerHandlerTest extends TestCase
{
    private function handler(InMemoryProviderCustomerRepository $customers): LinkProviderCustomerHandler
    {
        return new LinkProviderCustomerHandler($customers, new RecordingAuditLogWriter(), new SynchronousTransactions(), new FrozenClock('2026-09-11T12:00:00+00:00'));
    }

    #[Test]
    public function linksANewCustomer(): void
    {
        $customers = new InMemoryProviderCustomerRepository();
        $result = $this->handler($customers)->handle(new LinkProviderCustomerCommand(7, 1, 'user-1', 'cus_123'));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof LinkProviderCustomerResult);
        self::assertNotNull($customers->findByProviderCustomerId(1, 'cus_123'));
    }

    #[Test]
    public function isIdempotentForTheSameClient(): void
    {
        $customers = new InMemoryProviderCustomerRepository();
        $handler = $this->handler($customers);

        $first = $handler->handle(new LinkProviderCustomerCommand(7, 1, 'user-1', 'cus_123'));
        $second = $handler->handle(new LinkProviderCustomerCommand(7, 1, 'user-1', 'cus_123'));

        self::assertTrue($first->isOk());
        self::assertTrue($second->isOk());
        $firstValue = $first->value();
        $secondValue = $second->value();
        \assert($firstValue instanceof LinkProviderCustomerResult);
        \assert($secondValue instanceof LinkProviderCustomerResult);
        self::assertSame($firstValue->providerCustomerRowId, $secondValue->providerCustomerRowId);
    }

    #[Test]
    public function rejectsLinkingTheSameProviderCustomerIdToADifferentClient(): void
    {
        $customers = new InMemoryProviderCustomerRepository();
        $handler = $this->handler($customers);

        $handler->handle(new LinkProviderCustomerCommand(7, 1, 'user-1', 'cus_123'));
        $result = $handler->handle(new LinkProviderCustomerCommand(9, 1, 'user-2', 'cus_123'));

        self::assertTrue($result->isErr());
        self::assertSame('provider_customer.already_linked_to_another_client', $result->error()->code);
    }
}
