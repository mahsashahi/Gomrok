<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Http;

use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\AuthenticationMiddleware;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Shared\Infrastructure\CorrelationId;
use Gomrok\Tests\Support\StubClientAuthenticator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AuthenticationMiddlewareTest extends TestCase
{
    private ClientContext $context;

    protected function setUp(): void
    {
        $this->context = new ClientContext();
    }

    #[Test]
    public function passesAuthenticatedRequestsThroughAndPopulatesContext(): void
    {
        $client = new AuthenticatedClient(7, 'televika', 'Televika', 'active', 'EUR', 'DE', 'UTC', 'gk_live');
        $authenticator = StubClientAuthenticator::succeedingAs($client);

        [$handler, $response] = $this->dispatch($authenticator, 'Bearer gk_live_0123456789abcdef.secretsecretsecret');

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->context->isAuthenticated());
        self::assertSame(7, $this->context->clientId());
        self::assertSame($client, $handler->seenAttributes[AuthenticationMiddleware::ATTR_CLIENT] ?? null);
        self::assertSame(7, $handler->seenAttributes[AuthenticationMiddleware::ATTR_CLIENT_ID] ?? null);
        self::assertSame('gk_live', $handler->seenAttributes[AuthenticationMiddleware::ATTR_KEY_MODE] ?? null);
        self::assertSame('Bearer gk_live_0123456789abcdef.secretsecretsecret', $authenticator->lastHeader);
    }

    #[Test]
    public function returns401WithWwwAuthenticateOnCredentialFailure(): void
    {
        [$handler, $response] = $this->dispatch(StubClientAuthenticator::unauthorized(), null);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="gomrok"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($handler->wasCalled);

        /** @var array{code: string, status: int} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unauthorized', $body['code']);
        self::assertSame(401, $body['status']);
    }

    #[Test]
    public function returns403ForADisabledClient(): void
    {
        [$handler, $response] = $this->dispatch(StubClientAuthenticator::clientDisabled(), 'Bearer whatever');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('WWW-Authenticate'));
        self::assertFalse($handler->wasCalled);

        /** @var array{code: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('client_disabled', $body['code']);
    }

    /**
     * @return array{RequestHandlerInterface&object{wasCalled: bool, seenAttributes: array<string, mixed>}, ResponseInterface}
     */
    private function dispatch(StubClientAuthenticator $authenticator, ?string $authHeader): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/me');
        if ($authHeader !== null) {
            $request = $request->withHeader('Authorization', $authHeader);
        }

        $correlationId = new CorrelationId();
        $correlationId->set('corr-1');

        $middleware = new AuthenticationMiddleware(
            $authenticator,
            $this->context,
            $correlationId,
            new ResponseFactory(),
            new JsonResponder(),
        );

        $handler = new class () implements RequestHandlerInterface {
            public bool $wasCalled = false;

            /** @var array<string, mixed> */
            public array $seenAttributes = [];

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->wasCalled = true;
                foreach ([
                    AuthenticationMiddleware::ATTR_CLIENT,
                    AuthenticationMiddleware::ATTR_CLIENT_ID,
                    AuthenticationMiddleware::ATTR_KEY_MODE,
                ] as $name) {
                    $this->seenAttributes[$name] = $request->getAttribute($name);
                }

                return (new ResponseFactory())->createResponse();
            }
        };

        return [$handler, $middleware->process($request, $handler)];
    }
}
