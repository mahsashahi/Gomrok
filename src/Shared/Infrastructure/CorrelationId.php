<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure;

use Random\RandomException;

/**
 * Holds the current request's correlation id. One instance per request (the
 * container builds it fresh); {@see \Gomrok\Shared\Http\CorrelationIdMiddleware}
 * sets it, the logger processor reads it.
 */
final class CorrelationId
{
    private string $value = '';

    public function set(string $value): void
    {
        $this->value = $value;
    }

    public function get(): string
    {
        return $this->value;
    }

    /**
     * A short random token — not a ULID, no library. Enough entropy to correlate
     * log lines for one request.
     */
    public static function generate(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (RandomException) {
            return bin2hex((string) microtime(true)) . bin2hex((string) mt_rand());
        }
    }
}
