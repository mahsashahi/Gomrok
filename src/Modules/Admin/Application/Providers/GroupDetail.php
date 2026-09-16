<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

final readonly class GroupDetail
{
    /**
     * @param list<string>         $countriesRaw
     * @param list<string>         $purchaseTypesDisplay
     * @param list<string>         $purchaseTypesRaw
     * @param list<string>         $methodsDisplay
     * @param list<string>         $methodsRaw
     * @param list<GroupAccountRow> $accountRows
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public bool $isDefault,
        public bool $isActive,
        public ?string $deviceType,
        public ?string $currency,
        public string $countriesDisplay,
        public array $countriesRaw,
        public array $purchaseTypesDisplay,
        public array $purchaseTypesRaw,
        public array $methodsDisplay,
        public array $methodsRaw,
        public array $accountRows,
    ) {
    }
}
