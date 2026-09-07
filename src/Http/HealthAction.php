<?php

declare(strict_types=1);

namespace Gomrok\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Liveness probe. Deliberately does no I/O — it answers "the PHP app booted and
 * can route a request". Dependency health checks come in a later phase.
 */
final class HealthAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $payload = json_encode(
            ['status' => 'ok', 'service' => 'gomrok'],
            JSON_THROW_ON_ERROR,
        );

        $response->getBody()->write($payload);

        return $response->withHeader('Content-Type', 'application/json');
    }
}
