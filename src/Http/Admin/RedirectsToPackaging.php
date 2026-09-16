<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared "redirect back to the Packaging &amp; Pricing screen, carrying the
 * current tab/selection plus an optional flash message" behaviour used by
 * every Increment B write action (Phase 27) — a plain `?error=`/`?success=`
 * query param banner, not a session-backed flash store, matching this
 * screen's existing shareable-URL master-detail convention.
 */
trait RedirectsToPackaging
{
    /**
     * @param array<string, string|int|null> $params
     */
    private function redirectToPackaging(ResponseInterface $response, array $params, ?string $error = null, ?string $success = null): ResponseInterface
    {
        $query = array_filter($params, static fn (mixed $v): bool => $v !== null);
        if ($error !== null) {
            $query['error'] = $error;
        } elseif ($success !== null) {
            $query['success'] = $success;
        }

        $location = '/admin/packaging' . ($query !== [] ? '?' . http_build_query($query) : '');

        return $response->withStatus(302)->withHeader('Location', $location);
    }
}
