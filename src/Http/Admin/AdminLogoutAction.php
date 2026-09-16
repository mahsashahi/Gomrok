<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Admin\Application\LogoutAdminHandler;
use Gomrok\Shared\Http\AdminAuthenticationMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/logout` (Phase 27) — revokes the session and clears the
 * cookie, then redirects to the login screen.
 */
final readonly class AdminLogoutAction
{
    public function __construct(private LogoutAdminHandler $handler)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $token = \is_string($cookies[AdminAuthenticationMiddleware::COOKIE_NAME] ?? null)
            ? $cookies[AdminAuthenticationMiddleware::COOKIE_NAME]
            : null;

        if ($token !== null) {
            $this->handler->handle($token);
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/admin/login')
            ->withHeader('Set-Cookie', AdminAuthenticationMiddleware::COOKIE_NAME . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax');
    }
}
