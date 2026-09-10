<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DI\Container;
use Gomrok\Bootstrap\AppFactory;
use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Application\Idempotency\IdempotencyStore;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientAuthenticator;
use Gomrok\Shared\Infrastructure\Persistence\NullErrorLogWriter;
use Gomrok\Tests\Support\InMemoryIdempotencyStore;
use Gomrok\Tests\Support\StubClientAuthenticator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Wires the real Slim app (with the DB-touching adapters stubbed) and checks the
 * public / authenticated route split.
 */
final class ApiRoutingTest extends TestCase
{
    #[Test]
    public function healthIsPublicAndLeaksNothing(): void
    {
        $response = $this->handle(StubClientAuthenticator::unauthorized(), 'GET', '/health');

        self::assertSame(200, $response->getStatusCode());

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['status' => 'ok', 'service' => 'gomrok'], $body);
    }

    #[Test]
    public function apiRequiresAuthentication(): void
    {
        $response = $this->handle(StubClientAuthenticator::unauthorized(), 'GET', '/api/v1/me');

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="gomrok"', $response->getHeaderLine('WWW-Authenticate'));
    }

    #[Test]
    public function authenticatedRequestReachesTheAction(): void
    {
        $client = new AuthenticatedClient(7, 'televika', 'Televika', 'active', 'EUR', 'DE', 'UTC', 'gk_live');
        $response = $this->handle(StubClientAuthenticator::succeedingAs($client), 'GET', '/api/v1/me');

        self::assertSame(200, $response->getStatusCode());

        /** @var array{slug: string, key_mode: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('televika', $body['slug']);
        self::assertSame('gk_live', $body['key_mode']);
    }

    private function handle(StubClientAuthenticator $authenticator, string $method, string $path): ResponseInterface
    {
        $container = ContainerFactory::create();
        self::assertInstanceOf(Container::class, $container);
        $container->set(ClientAuthenticator::class, $authenticator);
        $container->set(IdempotencyStore::class, new InMemoryIdempotencyStore());
        $container->set(ErrorLogWriter::class, new NullErrorLogWriter());

        $app = AppFactory::create($container);

        return $app->handle((new ServerRequestFactory())->createServerRequest($method, $path));
    }
}
