<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Domain\DomainError;
use InvalidArgumentException;

/**
 * One purchase type a package supports, with the config that is meaningful for
 * that type (Phase 12 Q1). A trial only applies to `subscription` /
 * `recurring_payment`; `durationMonths` is the entitlement length per purchase
 * (NULL = open-ended / provider-defined).
 */
final readonly class PackagePurchaseCapability
{
    private function __construct(
        public PurchaseType $purchaseType,
        public bool $hasTrial,
        public ?int $trialDays,
        public ?int $durationMonths,
    ) {
    }

    public static function of(
        PurchaseType $purchaseType,
        bool $hasTrial = false,
        ?int $trialDays = null,
        ?int $durationMonths = null,
    ): self {
        $error = self::validate($purchaseType, $hasTrial, $trialDays, $durationMonths);
        if ($error !== null) {
            throw new InvalidArgumentException($error->message);
        }

        return new self($purchaseType, $hasTrial, $hasTrial ? $trialDays : null, $durationMonths);
    }

    public static function validate(
        PurchaseType $purchaseType,
        bool $hasTrial,
        ?int $trialDays,
        ?int $durationMonths,
    ): ?DomainError {
        if ($hasTrial) {
            if ($trialDays === null || $trialDays < 1) {
                return DomainError::validation(
                    'package.trial_days_required',
                    "A trial for '{$purchaseType->value}' needs a positive trial_days.",
                    ['purchase_type' => $purchaseType->value],
                );
            }
            if (!\in_array($purchaseType, [PurchaseType::Subscription, PurchaseType::RecurringPayment], true)) {
                return DomainError::validation(
                    'package.trial_not_allowed',
                    "A trial is only valid for subscription or recurring_payment, not '{$purchaseType->value}'.",
                    ['purchase_type' => $purchaseType->value],
                );
            }
        }
        if ($durationMonths !== null && $durationMonths < 1) {
            return DomainError::validation(
                'package.invalid_duration',
                'duration_months must be a positive number of months.',
                ['purchase_type' => $purchaseType->value],
            );
        }

        return null;
    }
}
