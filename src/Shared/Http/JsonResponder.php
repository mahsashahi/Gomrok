<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Gomrok\Shared\Domain\DomainError;
use Psr\Http\Message\ResponseInterface;

/**
 * Writes JSON response bodies, including RFC 7807-style problem responses for
 * {@see DomainError}s.
 */
final class JsonResponder
{
    public function json(ResponseInterface $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function problem(ResponseInterface $response, DomainError $error): ResponseInterface
    {
        return $this->json($response, [
            'type' => 'about:blank',
            'title' => $error->message,
            'status' => $error->httpStatus(),
            'code' => $error->code,
            'errorType' => $error->type->value,
            'context' => (object) $error->context,
        ], $error->httpStatus());
    }
}
