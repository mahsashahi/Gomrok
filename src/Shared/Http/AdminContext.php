<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use LogicException;

/**
 * Holds the authenticated admin for the current request — mirrors
 * {@see ClientContext}. One instance per request (DI singleton).
 * {@see AdminAuthenticationMiddleware} populates it inside the `/admin`
 * group; views and handlers read it for "who am I" / permission checks.
 */
final class AdminContext
{
    private ?AuthenticatedAdmin $admin = null;

    public function set(AuthenticatedAdmin $admin): void
    {
        $this->admin = $admin;
    }

    public function isAuthenticated(): bool
    {
        return $this->admin !== null;
    }

    public function admin(): AuthenticatedAdmin
    {
        return $this->admin ?? throw new LogicException(
            'No authenticated admin in context — reached outside the authenticated admin group.',
        );
    }
}
