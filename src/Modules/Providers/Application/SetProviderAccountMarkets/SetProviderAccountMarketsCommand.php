<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\SetProviderAccountMarkets;

/**
 * Replace the countries and payment methods a provider account serves.
 */
final readonly class SetProviderAccountMarketsCommand
{
    /**
     * @param list<string> $countries
     * @param list<string> $methods
     */
    public function __construct(
        public int $accountId,
        public array $countries,
        public array $methods,
        public ?string $name = null,
        public ?int $actorId = null,
    ) {
    }
}
