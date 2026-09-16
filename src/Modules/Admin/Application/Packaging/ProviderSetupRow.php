<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class ProviderSetupRow
{
    public function __construct(
        public string $accountName,
        public string $providerTypeCode,
        public string $syncState,
        public ?string $providerSideName,
        public ?string $remoteId,
    ) {
    }
}
