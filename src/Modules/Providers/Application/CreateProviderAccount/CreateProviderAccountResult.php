<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\CreateProviderAccount;

final readonly class CreateProviderAccountResult
{
    public function __construct(
        public int $accountId,
        public string $slug,
        public string $mode,
        public string $secretLastFour,
    ) {
    }
}
