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

    public function client(): AuthenticatedClient
    {
        return $this->client ?? throw new LogicException('AuthResult has no client (failure result).');
    }
}
