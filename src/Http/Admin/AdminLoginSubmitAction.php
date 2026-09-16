<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use DateTimeInterface;
use Gomrok\Config\Settings;
use Gomrok\Modules\Admin\Application\AuthenticateAdmin\AuthenticateAdminCommand;
use Gomrok\Modules\Admin\Application\AuthenticateAdmin\AuthenticateAdminHandler;
use Gomrok\Modules\Admin\Application\AuthenticateAdmin\AuthenticateAdminResult;
use Gomrok\Shared\Http\AdminAuthenticationMiddleware;
use Gomrok\Shared\Http\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/login` (Phase 27) — verifies the submitted credentials via
 * {@see AuthenticateAdminHandler} and, on success, sets the session cookie
 * (`HttpOnly`, `SameSite=Lax`, `Secure` outside local dev) and redirects to
 * `return_to` (only when it's a safe same-site path) or `/admin`.
 */
final readonly class AdminLoginSubmitAction
{
    public function __construct(
        private AuthenticateAdminHandler $handler,
        private ViewRenderer $view,
        private Settings $settings,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];

        $email = \is_string($body['email'] ?? null) ? trim($body['email']) : '';
        $password = \is_string($body['password'] ?? null) ? $body['password'] : '';

        $query = $request->getQueryParams();
        $returnTo = \is_string($query['return_to'] ?? null) ? $query['return_to'] : null;

        if ($email === '' || $password === '') {
            return $this->view->render($response, 'login.html.twig', [
                'error' => 'Email and password are required.',
                'email' => $email,
                'return_to' => $returnTo,
            ], 422);
        }

        $result = $this->handler->handle(new AuthenticateAdminCommand(
            $email,
            $password,
            $this->clientIp($request),
            $this->trimmedOrNull($request->getHeaderLine('User-Agent')),
        ));

        if ($result->isErr()) {
            return $this->view->render($response, 'login.html.twig', [
                'error' => $result->error()->message,
                'email' => $email,
                'return_to' => $returnTo,
            ], 401);
        }

        $value = $result->value();
        \assert($value instanceof AuthenticateAdminResult);

        $location = $this->safeReturnTo($returnTo) ?? '/admin';

        return $response
            ->withStatus(302)
            ->withHeader('Location', $location)
            ->withHeader('Set-Cookie', $this->cookie($value));
    }

    private function cookie(AuthenticateAdminResult $result): string
    {
        $attributes = [
            AdminAuthenticationMiddleware::COOKIE_NAME . '=' . $result->sessionToken,
            'Path=/',
            'Expires=' . $result->expiresAt->format(DateTimeInterface::COOKIE),
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($this->settings->appEnv === 'production') {
            $attributes[] = 'Secure';
        }

        return implode('; ', $attributes);
    }

    /** Only a same-site, root-relative path is honoured — never an absolute URL (open-redirect guard). */
    private function safeReturnTo(?string $returnTo): ?string
    {
        if ($returnTo === null || $returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
            return null;
        }

        return $returnTo;
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
