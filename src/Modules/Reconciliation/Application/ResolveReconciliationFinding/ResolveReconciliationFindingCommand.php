<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Application\ResolveReconciliationFinding;

final readonly class ResolveReconciliationFindingCommand
{
    public function __construct(
        public int $findingId,
        public int $actorId,
    ) {
    }
}
