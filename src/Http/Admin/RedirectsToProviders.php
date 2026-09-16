<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared "redirect back to the Providers screen, carrying the current
 * tab/selection plus an optional flash message" behaviour used by every
 * write action on that screen (Phase 27) — same shape as
 * {@see RedirectsToPackaging}, kept as its own small trait rather than a
 * shared abstraction since the two screens' redirect targets differ.
 */
trait RedirectsToProviders
{
    /**
     * @param array<string, string|int|null> $params
     */
    private function redirectToProviders(ResponseInterface $response, array $params, ?string $error = null, ?string $success = null): ResponseInterface
    {
        $query = array_filter($params, static fn (mixed $v): bool => $v !== null);
        if ($error !== null) {
            $query['error'] = $error;
        } elseif ($success !== null) {
            $query['success'] = $success;
        }

        $location = '/admin/providers' . ($query !== [] ? '?' . http_build_query($query) : '');

        return $response->withStatus(302)->withHeader('Location', $location);
    }
}
