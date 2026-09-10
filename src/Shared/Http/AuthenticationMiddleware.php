<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Gomrok\Shared\Infrastructure\CorrelationId;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Authenticates every request in the `/api/v1` group against a client API key
 * (`Authorization: Bearer gk_<mode>_<key_id>.<secret>` — Phase 7 Q1). On success
 * it populates {@see ClientContext} and sets the `authClient` / `authClientId` /
 * `authKeyMode` request attributes; on failure it returns the problem response
 * directly (401 for any credential issue, 403 for a disabled client — Q4).
 *
 * Registered on the group only — public routes never see it.
 */
final readonly class AuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTR_CLIENT = 'authClient';
    public const ATTR_CLIENT_ID = 'authClientId';
    public const ATTR_KEY_MODE = 'authKeyMode';

    public function __construct(
        private ClientAuthenticator $authenticator,
        private ClientContext $context,
        private CorrelationId $correlationId,
        private ResponseFactoryInterface $responseFactory,
        private JsonResponder $responder,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');

        $result = $this->authenticator->authenticate(
            $header === '' ? null : $header,
            new AuthRequestMeta(
                ip: $this->clientIp($request),
                userAgent: $this->trimmedOrNull($request->getHeaderLine('User-Agent')),
                correlationId: $this->trimmedOrNull($this->correlationId->get()),
            ),
        );

        if (!$result->ok) {
            return $this->failure($result);
        }

        $client = $result->client();
        $this->context->set($client);

        return $handler->handle(
            $request
                ->withAttribute(self::ATTR_CLIENT, $client)
                ->withAttribute(self::ATTR_CLIENT_ID, $client->id)
                ->withAttribute(self::ATTR_KEY_MODE, $client->keyMode),
        );
    }

    private function failure(AuthResult $result): ResponseInterface
    {
        $response = $this->responder->json(
            $this->responseFactory->createResponse($result->failureStatus),
            [
                'type' => 'about:blank',
                'title' => $result->failureTitle,
                'status' => $result->failureStatus,
                'code' => $result->failureCode,
            ],
            $result->failureStatus,
        );

        if ($result->failureStatus === 401) {
            $response = $response->withHeader('WWW-Authenticate', 'Bearer realm="gomrok"');
        }

        return $response;
    }

    private function clientIp(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();

        return isset($params['REMOTE_ADDR']) && \is_string($params['REMOTE_ADDR']) && $params['REMOTE_ADDR'] !== ''
            ? $params['REMOTE_ADDR']
            : null;
    }

    private function trimmedOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
