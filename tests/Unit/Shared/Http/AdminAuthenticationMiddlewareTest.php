<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Http;

use Gomrok\Shared\Http\AdminAuthenticationMiddleware;
use Gomrok\Shared\Http\AdminContext;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Shared\Infrastructure\CorrelationId;
use Gomrok\Tests\Support\StubAdminAuthenticator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class AdminAuthenticationMiddlewareTest extends TestCase
{
    private AdminContext $context;

    protected function setUp(): void
    {
        $this->context = new AdminContext();
    }

    #[Test]
    public function passesAuthenticatedRequestsThroughAndPopulatesContext(): void
    {
        $admin = new AuthenticatedAdmin(1, 'Ada Admin', 'admin@gomrok.test', 'admin');
        $authenticator = StubAdminAuthenticator::succeedingAs($admin);

        [$handler, $response] = $this->dispatch($authenticator, 'a-valid-token');

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->context->isAuthenticated());
        self::assertSame($admin, $this->context->admin());
        self::assertSame($admin, $handler->seenAttribute);
        self::assertSame('a-valid-token', $authenticator->lastToken);
    }

    #[Test]
    public function redirectsToLoginWhenTheCookieIsMissing(): void
    {
        [$handler, $response] = $this->dispatch(StubAdminAuthenticator::unauthorized(), null);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('/admin/login', $response->getHeaderLine('Location'));
        self::assertFalse($handler->wasCalled);
    }

    #[Test]
    public function redirectsToLoginAndClearsTheCookieOnAnInvalidToken(): void
    {
        [$handler, $response] = $this->dispatch(StubAdminAuthenticator::unauthorized(), 'stale-token');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertFalse($handler->wasCalled);
    }

    #[Test]
    public function redirectsToLoginWhenTheAccountBecameUnusable(): void
    {
        [$handler, $response] = $this->dispatch(StubAdminAuthenticator::accountUnusable(), 'some-token');

        self::assertSame(302, $response->getStatusCode());
        self::assertFalse($handler->wasCalled);
    }

    /**
     * @return array{RequestHandlerInterface&object{wasCalled: bool, seenAttribute: mixed}, ResponseInterface}
     */
    private function dispatch(StubAdminAuthenticator $authenticator, ?string $token): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin');
        if ($token !== null) {
            $request = $request->withCookieParams([AdminAuthenticationMiddleware::COOKIE_NAME => $token]);
        }

        $correlationId = new CorrelationId();
        $correlationId->set('corr-1');

        $middleware = new AdminAuthenticationMiddleware(
            $authenticator,
            $this->context,
            $correlationId,
            new ResponseFactory(),
        );

        $handler = new class () implements RequestHandlerInterface {
            public bool $wasCalled = false;
            public mixed $seenAttribute = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->wasCalled = true;
                $this->seenAttribute = $request->getAttribute(AdminAuthenticationMiddleware::ATTR_ADMIN);

                return (new ResponseFactory())->createResponse();
            }
        };

        return [$handler, $middleware->process($request, $handler)];
    }
}
