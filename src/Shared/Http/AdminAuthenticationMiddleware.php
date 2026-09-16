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
 * Authenticates every request in the `/admin` group against the
 * `gomrok_admin_session` cookie — mirrors {@see AuthenticationMiddleware},
 * but a browser-facing panel redirects to the login screen on failure
 * instead of returning a JSON problem body (Phase 27).
 */
final readonly class AdminAuthenticationMiddleware implements MiddlewareInterface
{
    public const ATTR_ADMIN = 'authAdmin';
    public const COOKIE_NAME = 'gomrok_admin_session';

    public function __construct(
        private AdminAuthenticator $authenticator,
        private AdminContext $context,
        private CorrelationId $correlationId,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $token = \is_string($cookies[self::COOKIE_NAME] ?? null) ? $cookies[self::COOKIE_NAME] : null;

        $result = $this->authenticator->authenticate(
            $token,
            new AuthRequestMeta(
                ip: $this->clientIp($request),
                userAgent: $this->trimmedOrNull($request->getHeaderLine('User-Agent')),
                correlationId: $this->trimmedOrNull($this->correlationId->get()),
            ),
        );

        if (!$result->ok) {
            return $this->redirectToLogin($request);
        }

        $admin = $result->admin();
        $this->context->set($admin);

        return $handler->handle($request->withAttribute(self::ATTR_ADMIN, $admin));
    }

    private function redirectToLogin(ServerRequestInterface $request): ResponseInterface
    {
        $returnTo = (string) $request->getUri()->withScheme('')->withHost('')->withPort(null);
        $location = '/admin/login' . ($returnTo !== '' && $returnTo !== '/admin' ? '?return_to=' . rawurlencode($returnTo) : '');

        return $this->responseFactory->createResponse(302)
            ->withHeader('Location', $location)
            ->withHeader('Set-Cookie', self::COOKIE_NAME . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax');
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
