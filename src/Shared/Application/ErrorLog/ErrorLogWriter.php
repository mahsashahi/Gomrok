<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\ErrorLog;

/**
 * Port for the explicit error log (decision: `PhaseResults/PhaseDecisions.md` Phase 5 Q3 —
 * explicit writer only, never a log handler). Called deliberately from the HTTP
 * error handler and, from their own phases, provider / webhook / notification /
 * job failure points.
 *
 * Implementations must never throw out of {@see self::log()} — a logging failure
 * must not mask the original error.
 */
interface ErrorLogWriter
{
    public function log(ErrorLogEntry $entry): void;
}
