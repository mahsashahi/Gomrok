<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\UpdateClient;

/**
 * Partial update of a client's display name and market defaults. A `null` field
 * means "leave unchanged"; set `clearDefaultCountry` to remove the country.
 * The `slug` is immutable and not updatable.
 */
final readonly class UpdateClientCommand
{
    public function __construct(
        public int $clientId,
        public ?string $name = null,
        public ?string $defaultCurrency = null,
        public ?string $defaultCountry = null,
        public bool $clearDefaultCountry = false,
        public ?string $timezone = null,
    ) {
    }
}
