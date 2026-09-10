<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPricingGroupCountries;

/**
 * Replace a pricing group's country set (full replace). The default group takes
 * no countries.
 */
final readonly class SetPricingGroupCountriesCommand
{
    /**
     * @param list<string> $countries ISO 3166-1 alpha-2
     */
    public function __construct(
        public int $groupId,
        public array $countries,
        public ?int $actorId = null,
    ) {
    }
}
