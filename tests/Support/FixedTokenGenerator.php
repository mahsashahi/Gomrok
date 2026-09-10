<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\TokenGenerator;

/**
 * Deterministic {@see TokenGenerator}: each call returns a value derived from a
 * counter, so tokens are predictable and unique within a test.
 */
final class FixedTokenGenerator implements TokenGenerator
{
    private int $calls = 0;

    public function hex(int $bytes): string
    {
        return substr(str_repeat(dechex(++$this->calls), $bytes * 2), 0, $bytes * 2);
    }

    public function urlSafe(int $bytes): string
    {
        return 'secret' . str_pad((string) (++$this->calls), max(0, $bytes - 6), '0', \STR_PAD_LEFT);
    }
}
