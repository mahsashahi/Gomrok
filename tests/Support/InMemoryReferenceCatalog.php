<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\ReferenceCatalog;

final class InMemoryReferenceCatalog implements ReferenceCatalog
{
    /**
     * @param list<string> $currencies
     * @param list<string> $countries
     */
    public function __construct(
        private array $currencies = ['EUR', 'USD', 'TRY', 'GBP'],
        private array $countries = ['DE', 'US', 'TR', 'GB', 'NL'],
    ) {
    }

    public function currencyExists(string $code): bool
    {
        return \in_array(strtoupper($code), $this->currencies, true);
    }

    public function countryExists(string $code): bool
    {
        return \in_array(strtoupper($code), $this->countries, true);
    }
}
