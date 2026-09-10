<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceRule;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PriceRuleAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PriceRule;
use Gomrok\Modules\Pricing\Domain\PriceRuleRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Upserts a price rule by its dimension tuple. Validates every dimension against
 * the client's own groups / countries / provider accounts and the enum values,
 * plus the structural guards on {@see PriceRule::validate}.
 */
final readonly class SetPriceRuleHandler
{
    public function __construct(
        private PriceRuleRepository $rules,
        private PackageDirectory $packages,
        private PricingGroupRepository $groups,
        private ProviderAccountDirectory $providerAccounts,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetPriceRuleCommand $command): Result
    {
        $package = $this->packages->findById($command->packageId);
        if ($package === null || $package->clientId !== $command->clientId) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found for this client."));
        }

        $method = null;
        if ($command->paymentMethod !== null) {
            $method = PaymentMethod::tryFrom($command->paymentMethod);
            if ($method === null) {
                return Result::err(DomainError::validation('price_rule.unknown_method', "Unknown payment method '{$command->paymentMethod}'.", ['method' => $command->paymentMethod]));
            }
        }

        $purchaseType = null;
        if ($command->purchaseType !== null) {
            $purchaseType = PurchaseType::tryFrom($command->purchaseType);
            if ($purchaseType === null) {
                return Result::err(DomainError::validation('price_rule.unknown_purchase_type', "Unknown purchase type '{$command->purchaseType}'.", ['purchase_type' => $command->purchaseType]));
            }
        }

        $interval = null;
        if ($command->subscriptionInterval !== null) {
            $interval = SubscriptionInterval::tryFrom($command->subscriptionInterval);
            if ($interval === null) {
                return Result::err(DomainError::validation('price_rule.unknown_interval', "Unknown subscription interval '{$command->subscriptionInterval}'.", ['interval' => $command->subscriptionInterval]));
            }
        }

        $currency = null;
        if ($command->currencyCode !== null) {
            try {
                $currency = Currency::of($command->currencyCode)->code();
            } catch (InvalidArgumentException) {
                return Result::err(DomainError::validation('price_rule.invalid_currency', "'{$command->currencyCode}' is not a valid ISO 4217 currency."));
            }
            if (!$this->reference->currencyExists($currency)) {
                return Result::err(DomainError::validation('price_rule.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
            }
        }

        $country = null;
        if ($command->countryCode !== null) {
            $country = strtoupper(trim($command->countryCode));
            if (!$this->reference->countryExists($country)) {
                return Result::err(DomainError::validation('price_rule.unknown_country', "Country '{$country}' is not a configured market.", ['country' => $country]));
            }
        }

        $group = null;
        if ($command->pricingGroupId !== null) {
            $group = $this->groups->findById($command->pricingGroupId);
            if ($group === null || $group->clientId() !== $command->clientId) {
                return Result::err(DomainError::validation('price_rule.group_not_owned', "Pricing group {$command->pricingGroupId} does not belong to this client.", ['pricing_group_id' => $command->pricingGroupId]));
            }
        }

        if ($command->providerAccountId !== null) {
            $owned = false;
            foreach ($this->providerAccounts->forClient($command->clientId) as $summary) {
                if ($summary->id === $command->providerAccountId) {
                    $owned = true;

                    break;
                }
            }
            if (!$owned) {
                return Result::err(DomainError::validation('price_rule.account_not_owned', "Provider account {$command->providerAccountId} does not belong to this client.", ['provider_account_id' => $command->providerAccountId]));
            }
        }

        $error = PriceRule::validate($command->pricingGroupId, $purchaseType, $interval, $command->isAvailable, $command->amountMinor, $currency);
        if ($error !== null) {
            return Result::err($error);
        }

        if ($command->isAvailable && $group !== null && $currency !== null && $group->currencyCode() !== $currency) {
            return Result::err(DomainError::validation(
                'price_rule.currency_mismatch',
                "The rule currency ({$currency}) must match the pinned pricing group's currency ({$group->currencyCode()}).",
                ['currency' => $currency, 'group_currency' => $group->currencyCode()],
            ));
        }

        $now = $this->clock->now();
        $existing = $this->rules->findByDimensions(
            $command->packageId,
            $command->pricingGroupId,
            $country,
            $command->providerAccountId,
            $method?->value,
            $purchaseType?->value,
            $interval?->value,
            $currency,
        );

        $before = $existing !== null ? PriceRuleAuditSnapshot::of($existing) : null;
        $created = $existing === null;

        if ($existing !== null) {
            $existing->change($command->isAvailable, $command->amountMinor, $now);
            $rule = $existing;
        } else {
            $rule = PriceRule::create(
                $command->clientId,
                $command->packageId,
                $command->pricingGroupId,
                $country,
                $command->providerAccountId,
                $method,
                $purchaseType,
                $interval,
                $currency,
                $command->isAvailable,
                $command->amountMinor,
                $now,
            );
        }

        $this->transactions->run(function () use ($rule, $before, $command): void {
            $this->rules->save($rule);
            $ruleId = $rule->id();
            \assert($ruleId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'price_rule.set')
                : AuditEntry::forSystem('price_rule.set', $command->clientId);

            $this->audit->record(
                $entry
                    ->withTarget('price_rule', $ruleId)
                    ->withChange($before, PriceRuleAuditSnapshot::of($rule))
                    ->withContext(['package_id' => $command->packageId]),
            );
        });

        $ruleId = $rule->id();
        \assert($ruleId !== null);

        return Result::ok(new SetPriceRuleResult($ruleId, $created));
    }
}
