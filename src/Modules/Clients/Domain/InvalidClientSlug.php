<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

use InvalidArgumentException;

/**
 * Thrown by {@see ClientSlug::of()} for a malformed slug. Application code that
 * takes untrusted input validates with {@see ClientSlug::isValid()} first and
 * returns {@see ClientSlug::error()} rather than letting this propagate.
 */
final class InvalidClientSlug extends InvalidArgumentException
{
    public function __construct(string $value)
    {
        parent::__construct(\sprintf('Invalid client slug: "%s".', $value));
    }
}
