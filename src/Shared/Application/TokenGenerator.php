<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application;

/**
 * Source of cryptographically-random tokens. Behind a port so tests can supply
 * deterministic values; the real implementation wraps `random_bytes`.
 */
interface TokenGenerator
{
    /**
     * `$bytes` of randomness as lowercase hex (length `2 * $bytes`).
     */
    public function hex(int $bytes): string;

    /**
     * `$bytes` of randomness as unpadded URL-safe base64 (`A–Z a–z 0–9 - _`).
     */
    public function urlSafe(int $bytes): string;
}
