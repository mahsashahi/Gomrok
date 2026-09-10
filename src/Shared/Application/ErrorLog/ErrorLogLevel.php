<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\ErrorLog;

/**
 * Severity of an `error_logs` row. Deliberately only two values — this table is
 * an operator triage surface, not a mirror of the application log stream.
 */
enum ErrorLogLevel: string
{
    case Error = 'error';
    case Critical = 'critical';
}
