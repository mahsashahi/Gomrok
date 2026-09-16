<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\ErrorLog;

use DateTimeImmutable;

/**
 * Write port for the `error_logs.resolved_at` / `resolved_by` columns
 * (`.claude/docs/database-design.md`: "support its 'mark resolved' action").
 * There is no `ErrorLog` domain aggregate — an error log row is a plain
 * operational record, not a business entity with invariants — so this is a
 * direct persistence port rather than a repository around a rich object,
 * matching {@see ErrorLogWriter}'s own shape for the same table.
 */
interface ErrorLogResolver
{
    /**
     * @return bool false if no row with this id exists
     */
    public function markResolved(int $id, ?int $adminUserId, DateTimeImmutable $now): bool;

    /**
     * @return bool false if no row with this id exists
     */
    public function markUnresolved(int $id): bool;
}
