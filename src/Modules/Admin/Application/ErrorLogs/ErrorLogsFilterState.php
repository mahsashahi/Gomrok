<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ErrorLogs;

/**
 * The filter values currently applied, echoed back so the screen's filter
 * form stays populated after a search. `resolved` is `'all'` / `'unresolved'`
 * / `'resolved'` (a screen-layer string, not the boolean the underlying
 * {@see \Gomrok\Shared\Application\ErrorLog\ErrorLogFilter} takes) so the
 * default "just show me what needs attention" state is representable without
 * a nullable-boolean tri-state in the URL.
 */
final readonly class ErrorLogsFilterState
{
    public function __construct(
        public ?string $level,
        public ?string $source,
        public ?int $clientId,
        public string $resolved,
    ) {
    }
}
