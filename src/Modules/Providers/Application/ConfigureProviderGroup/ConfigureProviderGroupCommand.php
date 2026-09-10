<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\ConfigureProviderGroup;

/**
 * Replace a provider group's market scope in one shot: its countries, the
 * purchase types and methods it sells, and (optionally) its name / currency.
 * `countries`, `purchaseTypes` and `methods` are full sets — whatever is passed
 * becomes the new value.
 */
final readonly class ConfigureProviderGroupCommand
{
    /**
     * @param list<string> $countries     ISO 3166-1 alpha-2
     * @param list<string> $purchaseTypes `PurchaseType` values
     * @param list<string> $methods       `PaymentMethod` values
     */
    public function __construct(
        public int $groupId,
        public array $countries = [],
        public array $purchaseTypes = [],
        public array $methods = [],
        public ?string $name = null,
        public ?string $currencyCode = null,
        public bool $clearCurrency = false,
        public ?int $actorId = null,
    ) {
    }
}
