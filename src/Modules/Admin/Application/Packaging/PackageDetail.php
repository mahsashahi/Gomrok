<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class PackageDetail
{
    /**
     * @param list<ProviderSetupRow>    $providerSetup
     * @param list<PackagingByGroupRow> $byGroupRows
     */
    public function __construct(
        public int $id,
        public int $clientId,
        public string $code,
        public string $name,
        public ?string $description,
        public ?string $badge,
        public bool $highlighted,
        public string $durationLabel,
        public ?string $defaultPrice,
        public ?string $trialLabel,
        public string $status,
        public array $providerSetup,
        public array $byGroupRows,
        public bool $hasOneTimePayment,
        public bool $hasSubscription,
        public bool $subscriptionHasTrial,
        public ?int $subscriptionTrialDays,
        public ?int $durationMonthsRaw,
        public ?int $defaultPriceAmountMinor,
        public ?string $defaultPriceCurrency,
        public bool $isActive,
    ) {
    }
}
