<?php

declare(strict_types=1);

namespace Gomrok\Http\Admin;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The admin panel's client switcher (Phase 27 Q5 — a UI-scoped display
 * filter, not a permission boundary: admin/support_agent roles stay global
 * across every client regardless of which one is "active" here). Backed by
 * a plain cookie, not a session column — switching clients never re-scopes
 * what the admin is allowed to do, only what a screen currently displays.
 */
final class AdminActiveClientCookie
{
    public const NAME = 'gomrok_admin_client';

    /**
     * The cookie's client if it still exists, else the first client
     * (alphabetical, matching {@see ClientDirectory::all()}'s own order) as
     * a reasonable default, else `null` when there are no clients at all.
     */
    public static function resolve(ServerRequestInterface $request, ClientDirectory $clients): ?ClientSnapshot
    {
        $cookies = $request->getCookieParams();
        $cookieValue = $cookies[self::NAME] ?? null;

        if (\is_string($cookieValue) && ctype_digit($cookieValue)) {
            $client = $clients->findById((int) $cookieValue);
            if ($client !== null) {
                return $client;
            }
        }

        return $clients->all()[0] ?? null;
    }

    private function __construct()
    {
    }
}
