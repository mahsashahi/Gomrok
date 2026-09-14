<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain;

/**
 * The kind of an expected failure, and the HTTP status the boundary maps it to.
 */
enum ErrorType: string
{
    case Validation = 'validation';
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case Forbidden = 'forbidden';
    case Unauthorized = 'unauthorized';
    case Unsupported = 'unsupported';
    case RuleViolation = 'rule_violation';
    /**
     * A provider/gateway call genuinely failed (timeout, 5xx, network error) —
     * distinct from every other case above, which are the caller's own
     * request being invalid/conflicting/forbidden. Phase 24: the first
     * caller that catches a {@see \Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException}
     * and converts it (Phase 21 Q2's "the calling Application handler
     * catches this" — full retry/dead-letter policy is Phase 29).
     */
    case UpstreamFailure = 'upstream_failure';

    public function httpStatus(): int
    {
        return match ($this) {
            self::Validation, self::Unsupported, self::RuleViolation => 422,
            self::NotFound => 404,
            self::Conflict => 409,
            self::Forbidden => 403,
            self::Unauthorized => 401,
            self::UpstreamFailure => 502,
        };
    }
}
