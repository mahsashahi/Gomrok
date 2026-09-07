<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use Gomrok\Bootstrap\AppFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HealthActionTest extends TestCase
{
    #[Test]
    public function healthEndpointReturnsOk(): void
    {
        $app = AppFactory::create();
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{status: string, service: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertSame('gomrok', $body['service']);
    }
}
