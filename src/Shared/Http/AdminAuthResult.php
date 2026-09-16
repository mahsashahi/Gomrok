<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use LogicException;

/**
 * Outcome of {@see AdminAuthenticator::authenticate()} — mirrors
 * {@see AuthResult}. On failure it carries only the HTTP status + a generic
 * code; the specific reason is recorded server-side (`admin_login_attempts`
 * doesn't apply here — that's login, not session validation — but the same
 * "don't leak the specific reason" principle holds).
 */
final readonly class AdminAuthResult
{
    private function __construct(
        public bool $ok,
        private ?AuthenticatedAdmin $admin,
        public int $failureStatus,
        public string $failureCode,
    ) {
    }

    public static function success(AuthenticatedAdmin $admin): self
    {
        return new self(true, $admin, 0, '');
    }

    /** No session cookie, an unknown/expired/revoked token — 401. */
    public static function unauthorized(): self
    {
        return new self(false, null, 401, 'unauthorized');
    }

    /** A valid session, but the account is now disabled/locked — 403. */
    public static function accountUnusable(): self
    {
        return new self(false, null, 403, 'account_unusable');
    }

    public function admin(): AuthenticatedAdmin
    {
        return $this->admin ?? throw new LogicException('AdminAuthResult has no admin (failure result).');
    }
}
