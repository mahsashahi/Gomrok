<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs;

final readonly class JobsFilterState
{
    public function __construct(
        /** `all` | `pending` | `processing` | `done` | `failed` | `dead_lettered`. */
        public string $status = 'all',
        public ?string $type = null,
    ) {
    }
}
