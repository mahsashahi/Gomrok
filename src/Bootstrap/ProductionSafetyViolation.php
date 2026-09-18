<?php

declare(strict_types=1);

namespace Gomrok\Bootstrap;

use RuntimeException;

/**
 * Thrown by {@see ProductionSafetyGuard} when the resolved `Settings` look
 * unsafe to run outside `local`/`testing` (Phase 30A Q5). Deliberately fatal
 * — refusing to boot, not just logging, so a misconfiguration can't reach
 * production even if nobody was watching the logs.
 */
final class ProductionSafetyViolation extends RuntimeException
{
    /**
     * @param list<string> $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "Refusing to start outside local/testing — production-safety check failed:\n"
            . implode("\n", array_map(static fn (string $v): string => " - {$v}", $violations)),
        );
    }
}
