<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Reconciliation;

/**
 * The filter values currently applied, echoed back so the screen's filter
 * form stays populated after a search. `resolution` mirrors
 * {@see \Gomrok\Modules\Reconciliation\Application\ReconciliationFindingFilter}'s
 * own `'all'` / `'open'` / `'resolved'` values directly — unlike Error Logs'
 * screen-layer boolean translation, there's no nullable-boolean underneath
 * to bridge here.
 */
final readonly class ReconciliationFilterState
{
    public function __construct(
        public ?int $clientId = null,
        public string $resolution = 'open',
    ) {
    }
}
