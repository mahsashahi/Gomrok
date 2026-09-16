<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared "redirect back to the Vouchers screen, carrying the current selection
 * plus an optional flash message" behaviour for that screen's write actions
 * (Phase 27) — same shape as {@see RedirectsToPackaging} /
 * {@see RedirectsToProviders}.
 */
trait RedirectsToVouchers
{
    /**
     * @param array<string, string|int|null> $params
     */
    private function redirectToVouchers(ResponseInterface $response, array $params, ?string $error = null, ?string $success = null): ResponseInterface
    {
        $query = array_filter($params, static fn (mixed $v): bool => $v !== null);
        if ($error !== null) {
            $query['error'] = $error;
        } elseif ($success !== null) {
            $query['success'] = $success;
        }

        $location = '/admin/vouchers' . ($query !== [] ? '?' . http_build_query($query) : '');

        return $response->withStatus(302)->withHeader('Location', $location);
    }
}
