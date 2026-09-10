<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\Authenticate;

use Gomrok\Modules\Clients\Domain\AuthFailureReason;
use Gomrok\Shared\Http\AuthRequestMeta;

/**
 * One authentication attempt, ready to persist to `client_auth_attempts`.
 * `keyId` is the parsed token id only — the secret never reaches here.
 */
final readonly class AuthAttempt
{
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_FAILURE = 'failure';

    private function __construct(
        public string $outcome,
        public AuthFailureReason $reason,
        public ?string $keyId,
        public ?int $clientId,
        public ?string $ip,
        public ?string $userAgent,
        public ?string $correlationId,
    ) {
    }

    public static function success(string $keyId, int $clientId, AuthRequestMeta $meta): self
    {
        return new self(self::OUTCOME_SUCCESS, AuthFailureReason::Ok, $keyId, $clientId, $meta->ip, $meta->userAgent, $meta->correlationId);
    }

    public static function failure(AuthFailureReason $reason, ?string $keyId, ?int $clientId, AuthRequestMeta $meta): self
    {
        return new self(self::OUTCOME_FAILURE, $reason, $keyId, $clientId, $meta->ip, $meta->userAgent, $meta->correlationId);
    }
}
