<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Jobs\AcknowledgeJobAlert;

final readonly class AcknowledgeJobAlertCommand
{
    public function __construct(
        public int $jobId,
        public int $actorId,
    ) {
    }
}
