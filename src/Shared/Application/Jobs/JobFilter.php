<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application\Jobs;

final readonly class JobFilter
{
    public function __construct(
        public ?string $status = null,
        public ?string $type = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
