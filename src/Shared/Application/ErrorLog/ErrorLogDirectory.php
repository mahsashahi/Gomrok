<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\ErrorLog;

/**
 * Published read API over `error_logs` (Phase 27's Error Logs admin screen —
 * the only reader). Kept separate from {@see ErrorLogWriter} and from
 * {@see ErrorLogResolver}, mirroring every other write-port/read-port split
 * in the codebase.
 */
interface ErrorLogDirectory
{
    public function find(int $id): ?ErrorLogRecord;

    /**
     * Newest first.
     *
     * @return list<ErrorLogRecord>
     */
    public function search(ErrorLogFilter $filter): array;

    /**
     * Total rows matching the filter, ignoring its `limit`/`offset` — for
     * pagination.
     */
    public function countMatching(ErrorLogFilter $filter): int;

    /**
     * Every distinct `source` value ever recorded, for the screen's filter
     * dropdown — alphabetical.
     *
     * @return list<string>
     */
    public function distinctSources(): array;
}
