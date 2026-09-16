<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

final readonly class AccountDetail
{
    /**
     * @param list<string>                $countriesRaw
     * @param list<string>                $methodsRaw
     * @param list<string>                $capabilitiesDisplay
     * @param list<string>                $purchaseTypesDisplay
     * @param list<AccountGroupMembership> $groupMemberships
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public string $providerTypeCode,
        public string $providerTypeName,
        public string $mode,
        public string $status,
        public bool $isActive,
        public ?string $publicKey,
        public string $secretLastFour,
        public string $countriesDisplay,
        public array $countriesRaw,
        public string $methodsDisplay,
        public array $methodsRaw,
        public int $activeEndpointCount,
        public array $capabilitiesDisplay,
        public array $purchaseTypesDisplay,
        public array $groupMemberships,
    ) {
    }
}
