<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use Gomrok\Http\Api\MeAction;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MeActionTest extends TestCase
{
    #[Test]
    public function returnsTheAuthenticatedClient(): void
    {
        $context = new ClientContext();
        $context->set(new AuthenticatedClient(7, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'gk_test'));

        $action = new MeAction($context, new JsonResponder());
        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/me'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{id: int, slug: string, key_mode: string, default_country: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(7, $body['id']);
        self::assertSame('televika', $body['slug']);
        self::assertSame('gk_test', $body['key_mode']);
        self::assertSame('DE', $body['default_country']);
    }
}
