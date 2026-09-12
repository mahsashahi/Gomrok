<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

final readonly class ProviderRefundResult
{
    public function __construct(
        public string $providerReference,
        public int $amountMinor,
        public string $rawStatus,
    ) {
    }
}
