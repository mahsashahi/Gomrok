<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Gomrok\Shared\Infrastructure\CorrelationId;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Establishes a correlation id for the request — reusing a valid inbound
 * `X-Correlation-Id` header or generating one — exposes it as a request
 * attribute, and echoes it on the response.
 */
final readonly class CorrelationIdMiddleware implements MiddlewareInterface
{
    public const HEADER = 'X-Correlation-Id';
    public const ATTRIBUTE = 'correlationId';

    public function __construct(private CorrelationId $correlationId)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $inbound = $request->getHeaderLine(self::HEADER);
        $id = self::isAcceptable($inbound) ? $inbound : CorrelationId::generate();

        $this->correlationId->set($id);

        $response = $handler->handle($request->withAttribute(self::ATTRIBUTE, $id));

        return $response->withHeader(self::HEADER, $id);
    }

    private static function isAcceptable(string $id): bool
    {
        return $id !== ''
            && \strlen($id) <= 128
            && preg_match('/^[A-Za-z0-9._-]+$/', $id) === 1;
    }
}
