<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure;

use Gomrok\Shared\Application\TokenGenerator;
use RuntimeException;

/**
 * {@see TokenGenerator} backed by `random_bytes`.
 */
final class RandomTokenGenerator implements TokenGenerator
{
    public function hex(int $bytes): string
    {
        return bin2hex($this->random($bytes));
    }

    public function urlSafe(int $bytes): string
    {
        return rtrim(strtr(base64_encode($this->random($bytes)), '+/', '-_'), '=');
    }

    private function random(int $bytes): string
    {
        if ($bytes < 1) {
            throw new RuntimeException('Token byte length must be positive.');
        }

        return random_bytes($bytes);
    }
}
