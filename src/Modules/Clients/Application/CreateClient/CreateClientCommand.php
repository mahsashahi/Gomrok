<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\CreateClient;

use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;

/**
 * Create a client and issue its first API key in one step.
 */
final readonly class CreateClientCommand
{
    public function __construct(
        public string $slug,
        public string $name,
        public string $defaultCurrency,
        public ?string $defaultCountry = null,
        public string $timezone = 'UTC',
        public ApiKeyPrefix $firstKeyPrefix = ApiKeyPrefix::Live,
        public ?string $firstKeyLabel = 'initial',
    ) {
    }
}
