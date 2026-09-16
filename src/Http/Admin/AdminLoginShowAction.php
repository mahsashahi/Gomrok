<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Shared\Http\AdminAuthenticationMiddleware;
use Gomrok\Shared\Http\AdminAuthenticator;
use Gomrok\Shared\Http\AuthRequestMeta;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /admin/login` (Phase 27) — renders the sign-in form. Redirects
 * straight to `/admin` when the request already carries a valid session
 * cookie, so a signed-in admin visiting `/admin/login` directly lands on the
 * panel instead of a redundant form.
 */
final readonly class AdminLoginShowAction
{
    public function __construct(
        private AdminAuthenticator $authenticator,
        private ViewRenderer $view,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $cookies = $request->getCookieParams();
        $token = \is_string($cookies[AdminAuthenticationMiddleware::COOKIE_NAME] ?? null)
            ? $cookies[AdminAuthenticationMiddleware::COOKIE_NAME]
            : null;

        if ($token !== null && $this->authenticator->authenticate($token, new AuthRequestMeta())->ok) {
            return $response->withStatus(302)->withHeader('Location', '/admin');
        }

        $query = $request->getQueryParams();
        $returnTo = \is_string($query['return_to'] ?? null) ? $query['return_to'] : null;

        return $this->view->render($response, 'login.html.twig', ['return_to' => $returnTo]);
    }
}
