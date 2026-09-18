<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs\RunJobNow;

final readonly class RunJobNowCommand
{
    public function __construct(
        public int $jobId,
        public int $actorId,
    ) {
    }
}
