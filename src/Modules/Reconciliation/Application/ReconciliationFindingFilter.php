<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Application;

final readonly class ReconciliationFindingFilter
{
    public function __construct(
        public ?int $clientId = null,
        /** `all` | `open` | `resolved`. */
        public string $resolution = 'open',
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
