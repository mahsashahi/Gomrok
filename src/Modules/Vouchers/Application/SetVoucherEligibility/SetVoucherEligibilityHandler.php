<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\SetVoucherEligibility;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Vouchers\Application\VoucherAuditSnapshot;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityDimension;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRule;
use Gomrok\Modules\Vouchers\Domain\VoucherEligibilityRuleRepository;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * Full-replaces a voucher's `voucher_eligibility_rules`. Every `(dimension,
 * value)` pair is validated and de-duplicated before the swap.
 */
final readonly class SetVoucherEligibilityHandler
{
    public function __construct(
        private VoucherRepository $vouchers,
        private VoucherEligibilityRuleRepository $rules,
        private PackageDirectory $packages,
        private ProviderAccountDirectory $providerAccounts,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
    ) {
    }

    public function handle(SetVoucherEligibilityCommand $command): Result
    {
        $voucher = $this->vouchers->findById($command->voucherId);
        if ($voucher === null || $voucher->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher {$command->voucherId} was not found for this client."));
        }

        $normalised = [];
        foreach ($command->rules as $row) {
            $dimension = VoucherEligibilityDimension::tryFrom($row['dimension']);
            if ($dimension === null) {
                return Result::err(DomainError::validation('voucher_eligibility.unknown_dimension', "Unknown eligibility dimension '{$row['dimension']}'.", ['dimension' => $row['dimension']]));
            }

            $error = $this->validateValue($dimension, $row['value'], $command->clientId);
            if ($error !== null) {
                return Result::err($error);
            }

            $key = $dimension->value . ':' . $this->normaliseValue($dimension, $row['value']);
            $normalised[$key] = new VoucherEligibilityRule($command->voucherId, $dimension, $this->normaliseValue($dimension, $row['value']));
        }

        $before = VoucherAuditSnapshot::eligibilityRules($this->rules->forVoucher($command->voucherId));
        $ruleList = array_values($normalised);

        $this->transactions->run(function () use ($command, $ruleList, $before): void {
            $this->rules->replaceForVoucher($command->voucherId, $ruleList);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'voucher.eligibility_set')
                : AuditEntry::forSystem('voucher.eligibility_set', $command->clientId);

            $this->audit->record(
                $entry->withTarget('voucher', $command->voucherId)
                    ->withChange(['rules' => $before], ['rules' => VoucherAuditSnapshot::eligibilityRules($ruleList)]),
            );
        });

        return Result::ok(null);
    }

    private function validateValue(VoucherEligibilityDimension $dimension, string $value, int $clientId): ?DomainError
    {
        $value = trim($value);
        if ($value === '') {
            return DomainError::validation('voucher_eligibility.empty_value', 'An eligibility rule value cannot be empty.');
        }

        return match ($dimension) {
            VoucherEligibilityDimension::Country => $this->reference->countryExists(strtoupper($value))
                ? null
                : DomainError::validation('voucher_eligibility.unknown_country', "Country '{$value}' is not a configured market.", ['value' => $value]),
            VoucherEligibilityDimension::Currency => $this->reference->currencyExists(strtoupper($value))
                ? null
                : DomainError::validation('voucher_eligibility.unknown_currency', "Currency '{$value}' is not configured.", ['value' => $value]),
            VoucherEligibilityDimension::Package => $this->validatePackage($value, $clientId),
            VoucherEligibilityDimension::ProviderAccount => $this->validateProviderAccount($value, $clientId),
            VoucherEligibilityDimension::PaymentMethod => PaymentMethod::tryFrom($value) !== null
                ? null
                : DomainError::validation('voucher_eligibility.unknown_method', "Unknown payment method '{$value}'.", ['value' => $value]),
            VoucherEligibilityDimension::PurchaseType => PurchaseType::tryFrom($value) !== null
                ? null
                : DomainError::validation('voucher_eligibility.unknown_purchase_type', "Unknown purchase type '{$value}'.", ['value' => $value]),
            VoucherEligibilityDimension::SubscriptionInterval => SubscriptionInterval::tryFrom($value) !== null
                ? null
                : DomainError::validation('voucher_eligibility.unknown_interval', "Unknown subscription interval '{$value}'.", ['value' => $value]),
        };
    }

    private function validatePackage(string $value, int $clientId): ?DomainError
    {
        if (!ctype_digit($value)) {
            return DomainError::validation('voucher_eligibility.invalid_package', "'{$value}' is not a package id.", ['value' => $value]);
        }
        $package = $this->packages->findById((int) $value);
        if ($package === null || $package->clientId !== $clientId) {
            return DomainError::validation('voucher_eligibility.unknown_package', "Package {$value} does not belong to this client.", ['value' => $value]);
        }

        return null;
    }

    private function validateProviderAccount(string $value, int $clientId): ?DomainError
    {
        if (!ctype_digit($value)) {
            return DomainError::validation('voucher_eligibility.invalid_provider_account', "'{$value}' is not a provider account id.", ['value' => $value]);
        }
        $id = (int) $value;
        foreach ($this->providerAccounts->forClient($clientId) as $summary) {
            if ($summary->id === $id) {
                return null;
            }
        }

        return DomainError::validation('voucher_eligibility.unknown_provider_account', "Provider account {$value} does not belong to this client.", ['value' => $value]);
    }

    private function normaliseValue(VoucherEligibilityDimension $dimension, string $value): string
    {
        $value = trim($value);

        return match ($dimension) {
            VoucherEligibilityDimension::Country, VoucherEligibilityDimension::Currency => strtoupper($value),
            default => $value,
        };
    }
}
