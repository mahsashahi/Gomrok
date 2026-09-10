<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;

/**
 * Discards error-log entries. Used where no database is available (CLI tools
 * that must run without a DB, some test paths).
 */
final class NullErrorLogWriter implements ErrorLogWriter
{
    public function log(ErrorLogEntry $entry): void
    {
        // intentionally does nothing
    }
}
