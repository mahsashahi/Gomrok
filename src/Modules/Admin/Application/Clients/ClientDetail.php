<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Clients;

final readonly class ClientDetail
{
    /**
     * @param list<ApiKeyRow> $apiKeys
     * @param list<string>    $enabledProviders
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $status,
        public bool $isActive,
        public string $environment,
        public string $defaultCurrency,
        public ?string $defaultCountry,
        public string $timezone,
        public string $createdLabel,
        public array $apiKeys,
        public array $enabledProviders,
    ) {
    }
}
