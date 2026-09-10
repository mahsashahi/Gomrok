<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Audit;

use Psr\Clock\ClockInterface;

/**
 * Port for appending to `audit_logs`. Sensitive admin write paths call this;
 * the MySQL adapter lives in `Shared\Infrastructure\Persistence`.
 *
 * The implementation stamps `created_at` from a {@see ClockInterface} and
 * redacts secret-bearing keys in `before` / `after` / `context`.
 */
interface AuditLogWriter
{
    public function record(AuditEntry $entry): void;
}
