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

    public function httpStatus(): int
    {
        return match ($this) {
            self::Validation, self::Unsupported, self::RuleViolation => 422,
            self::NotFound => 404,
            self::Conflict => 409,
            self::Forbidden => 403,
            self::Unauthorized => 401,
        };
    }
}
