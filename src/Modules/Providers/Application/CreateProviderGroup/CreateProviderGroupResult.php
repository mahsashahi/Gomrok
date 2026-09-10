<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\CreateProviderGroup;

final readonly class CreateProviderGroupResult
{
    public function __construct(
        public int $groupId,
        public string $slug,
        public bool $isDefault,
    ) {
    }
}
