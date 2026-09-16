<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared "redirect back to the Clients screen, carrying the current filter and
 * selection plus an optional flash message" behaviour (Phase 27) — same shape
 * as {@see RedirectsToPackaging} / {@see RedirectsToProviders} /
 * {@see RedirectsToVouchers}. Only used for redirects that carry no secret;
 * an action whose success response includes a one-time plaintext API key
 * renders the screen directly instead (see {@see AdminClientsCreateAction}),
 * since that value must never appear in a URL.
 */
trait RedirectsToClients
{
    /**
     * @param array<string, string|int|null> $params
     */
    private function redirectToClients(ResponseInterface $response, array $params, ?string $error = null, ?string $success = null): ResponseInterface
    {
        $query = array_filter($params, static fn (mixed $v): bool => $v !== null);
        if ($error !== null) {
            $query['error'] = $error;
        } elseif ($success !== null) {
            $query['success'] = $success;
        }

        $location = '/admin/clients' . ($query !== [] ? '?' . http_build_query($query) : '');

        return $response->withStatus(302)->withHeader('Location', $location);
    }
}
