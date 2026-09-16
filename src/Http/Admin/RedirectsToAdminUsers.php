<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Psr\Http\Message\ResponseInterface;

/**
 * Shared "redirect back to the Admin Users screen with an optional flash
 * message" behaviour (Phase 27) — same shape as {@see RedirectsToClients} /
 * {@see RedirectsToVouchers}. No secret ever needs to travel through this
 * screen's redirects: passwords are typed by the acting admin, never
 * generated, so there is nothing to keep out of a URL the way Clients' API
 * keys required.
 */
trait RedirectsToAdminUsers
{
    private function redirectToAdminUsers(ResponseInterface $response, ?string $error = null, ?string $success = null): ResponseInterface
    {
        $query = [];
        if ($error !== null) {
            $query['error'] = $error;
        } elseif ($success !== null) {
            $query['success'] = $success;
        }

        $location = '/admin/admin-users' . ($query !== [] ? '?' . http_build_query($query) : '');

        return $response->withStatus(302)->withHeader('Location', $location);
    }
}
