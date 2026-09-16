<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Clients;

final readonly class ClientRow
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $environment,
        public string $enabledProvidersLabel,
        public string $status,
        public bool $isActive,
        public string $createdLabel,
        public bool $selected,
    ) {
    }
}
