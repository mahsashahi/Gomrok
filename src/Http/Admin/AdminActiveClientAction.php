<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /admin/active-client` (Phase 27) — sets which client the switcher
 * currently shows (see {@see AdminActiveClientCookie}) and redirects back to
 * `return_to` (only a safe same-site path) or `/admin`.
 */
final readonly class AdminActiveClientAction
{
    public function __construct(private ClientDirectory $clients)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = \is_array($body) ? $body : [];

        $clientId = \is_string($body['client_id'] ?? null) && ctype_digit($body['client_id']) ? (int) $body['client_id'] : null;
        $returnTo = \is_string($body['return_to'] ?? null) ? $body['return_to'] : null;
        $location = $this->safeReturnTo($returnTo) ?? '/admin';

        if ($clientId === null || !$this->clients->existsById($clientId)) {
            return $response->withStatus(302)->withHeader('Location', $location);
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', $location)
            ->withHeader('Set-Cookie', AdminActiveClientCookie::NAME . '=' . $clientId . '; Path=/; Max-Age=' . (60 * 60 * 24 * 365) . '; HttpOnly; SameSite=Lax');
    }

    private function safeReturnTo(?string $returnTo): ?string
    {
        if ($returnTo === null || $returnTo === '' || $returnTo[0] !== '/' || str_starts_with($returnTo, '//')) {
            return null;
        }

        return $returnTo;
    }
}
