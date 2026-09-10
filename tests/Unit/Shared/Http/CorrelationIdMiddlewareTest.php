<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Http;

use Gomrok\Shared\Http\CorrelationIdMiddleware;
use Gomrok\Shared\Infrastructure\CorrelationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class CorrelationIdMiddlewareTest extends TestCase
{
    #[Test]
    public function generatesAnIdWhenNoneIsSupplied(): void
    {
        [$holder, $handler, $response] = $this->dispatch(null);

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $holder->get());
        self::assertSame($holder->get(), $handler->seenAttribute);
        self::assertSame($holder->get(), $response->getHeaderLine(CorrelationIdMiddleware::HEADER));
    }

    #[Test]
    public function reusesAValidInboundHeader(): void
    {
        [$holder, $handler, $response] = $this->dispatch('trace-9f8e.7d');

        self::assertSame('trace-9f8e.7d', $holder->get());
        self::assertSame('trace-9f8e.7d', $handler->seenAttribute);
        self::assertSame('trace-9f8e.7d', $response->getHeaderLine(CorrelationIdMiddleware::HEADER));
    }

    #[Test]
    public function ignoresAMalformedInboundHeader(): void
    {
        [$holder] = $this->dispatch('bad id with spaces');

        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $holder->get());
    }

    /**
     * @return array{CorrelationId, RequestHandlerInterface&object{seenAttribute: string}, ResponseInterface}
     */
    private function dispatch(?string $inbound): array
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        if ($inbound !== null) {
            $request = $request->withHeader(CorrelationIdMiddleware::HEADER, $inbound);
        }

        $handler = new class () implements RequestHandlerInterface {
            public string $seenAttribute = '';

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $value = $request->getAttribute(CorrelationIdMiddleware::ATTRIBUTE);
                $this->seenAttribute = \is_string($value) ? $value : '';

                return (new ResponseFactory())->createResponse();
            }
        };

        $holder = new CorrelationId();
        $response = (new CorrelationIdMiddleware($holder))->process($request, $handler);

        return [$holder, $handler, $response];
    }
}
