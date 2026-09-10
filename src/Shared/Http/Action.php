<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base class for module HTTP actions. Keeps controllers thin: parse input →
 * call one use case → turn the {@see Result} into a response.
 */
abstract class Action
{
    public function __construct(protected readonly JsonResponder $responder)
    {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->handle($request, $response, $args);
    }

    /**
     * @param array<string, string> $args
     */
    abstract protected function handle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface;

    /**
     * Map a use-case {@see Result} to a response: the value as JSON on success,
     * a problem document on a {@see DomainError}.
     */
    protected function respond(ResponseInterface $response, Result $result, int $okStatus = 200): ResponseInterface
    {
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        return $this->responder->json($response, $result->value(), $okStatus);
    }

    protected function error(ResponseInterface $response, DomainError $error): ResponseInterface
    {
        return $this->responder->problem($response, $error);
    }
}
