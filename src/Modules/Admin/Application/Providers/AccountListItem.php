<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

final readonly class AccountListItem
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $providerTypeCode,
        public string $mode,
        public string $status,
        public bool $selected,
    ) {
    }
}
