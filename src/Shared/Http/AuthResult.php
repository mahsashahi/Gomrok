<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use LogicException;

/**
 * Outcome of {@see ClientAuthenticator::authenticate()}. On failure it carries
 * only the HTTP status + a generic code — the specific reason is recorded
 * server-side, never returned (Phase 7 Q4).
 */
final readonly class AuthResult
{
    private function __construct(
        public bool $ok,
        private ?AuthenticatedClient $client,
        public int $failureStatus,
        public string $failureCode,
        public string $failureTitle,
        public ?int $retryAfterSeconds = null,
    ) {
    }

    public static function success(AuthenticatedClient $client): self
    {
        return new self(true, $client, 0, '', '');
    }

    /** Any problem with the credential itself — 401, generic body. */
    public static function unauthorized(): self
    {
        return new self(false, null, 401, 'unauthorized', 'Authentication required');
    }

    /** Valid key, but the client is disabled — 403. */
    public static function clientDisabled(): self
    {
        return new self(false, null, 403, 'client_disabled', 'Client is disabled');
    }

    /**
     * Too many recent failed attempts for this credential (Phase 30A Q1) —
     * 429, not 401: doesn't confirm or deny the credential's own validity,
     * only that this identifier has attracted too many recent failures.
     */
    public static function tooManyAttempts(int $retryAfterSeconds): self
    {
        return new self(false, null, 429, 'rate_limited', 'Too many failed authentication attempts. Try again later.', $retryAfterSeconds);
    }

    public function client(): AuthenticatedClient
    {
        return $this->client ?? throw new LogicException('AuthResult has no client (failure result).');
    }
}
