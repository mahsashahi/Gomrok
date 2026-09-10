<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Http;

use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientContextTest extends TestCase
{
    #[Test]
    public function isUnauthenticatedUntilSet(): void
    {
        $context = new ClientContext();

        self::assertFalse($context->isAuthenticated());

        $this->expectException(LogicException::class);
        $context->client();
    }

    #[Test]
    public function exposesTheClientOnceSet(): void
    {
        $context = new ClientContext();
        $context->set(new AuthenticatedClient(7, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'gk_live'));

        self::assertTrue($context->isAuthenticated());
        self::assertSame(7, $context->clientId());
        self::assertSame('televika', $context->client()->slug);
        self::assertSame('gk_live', $context->client()->keyMode);
    }
}
