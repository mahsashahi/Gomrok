<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Audit;

/**
 * Published read API over `audit_logs` (Phase 27's Audit Logs admin screen —
 * the only reader; nothing in the write path ever queries its own trail
 * back). Kept separate from {@see AuditLogWriter}, mirroring every other
 * write-port/read-port split in the codebase (e.g. `ClientRepository` vs.
 * `ClientDirectory`).
 */
interface AuditLogDirectory
{
    /**
     * Newest first.
     *
     * @return list<AuditLogEntry>
     */
    public function search(AuditLogFilter $filter): array;

    /**
     * Total rows matching the filter, ignoring its `limit`/`offset` — for
     * pagination.
     */
    public function countMatching(AuditLogFilter $filter): int;

    /**
     * Every distinct `action` value ever recorded, for the screen's filter
     * dropdown — alphabetical.
     *
     * @return list<string>
     */
    public function distinctActions(): array;
}
