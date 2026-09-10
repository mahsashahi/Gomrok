<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\ConfigureProviderGroup;

use Gomrok\Modules\Providers\Application\ProviderGroupAuditSnapshot;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Sets a provider group's country / purchase-type / method scope. Enforces that
 * the default group holds no countries and that a country is claimed by at most
 * one non-default group per (client, device type).
 */
final readonly class ConfigureProviderGroupHandler
{
    public function __construct(
        private ProviderGroupRepository $groups,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ConfigureProviderGroupCommand $command): Result
    {
        $group = $this->groups->findById($command->groupId);
        if ($group === null) {
            return Result::err(DomainError::notFound('provider_group.not_found', "Provider group {$command->groupId} was not found."));
        }

        $countries = self::normaliseCountries($command->countries);
        if ($group->isDefault() && $countries !== []) {
            return Result::err(DomainError::validation(
                'provider_group.default_takes_no_countries',
                'The default group covers every country no other group claims; it cannot list countries.',
            ));
        }

        foreach ($countries as $country) {
            if (!$this->reference->countryExists($country)) {
                return Result::err(DomainError::validation(
                    'provider_group.unknown_country',
                    "Country '{$country}' is not a configured market.",
                    ['country' => $country],
                ));
            }
        }

        $purchaseTypes = [];
        foreach ($command->purchaseTypes as $value) {
            $purchaseType = PurchaseType::tryFrom($value);
            if ($purchaseType === null) {
                return Result::err(DomainError::validation('provider_group.unknown_purchase_type', "Unknown purchase type '{$value}'.", ['purchase_type' => $value]));
            }
            $purchaseTypes[] = $purchaseType;
        }

        $methods = [];
        foreach ($command->methods as $value) {
            $method = PaymentMethod::tryFrom($value);
            if ($method === null) {
                return Result::err(DomainError::validation('provider_group.unknown_method', "Unknown payment method '{$value}'.", ['method' => $value]));
            }
            $methods[] = $method;
        }

        $currency = null;
        if (!$command->clearCurrency && $command->currencyCode !== null && $command->currencyCode !== '') {
            $currency = strtoupper($command->currencyCode);
            if (!$this->reference->currencyExists($currency)) {
                return Result::err(DomainError::validation('provider_group.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
            }
        }

        $clash = $this->firstCountryClash($group, $countries);
        if ($clash !== null) {
            return Result::err(DomainError::conflict(
                'provider_group.country_already_grouped',
                "Country '{$clash[0]}' is already in group '{$clash[1]}' for this client.",
                ['country' => $clash[0], 'group' => $clash[1]],
            ));
        }

        $before = ProviderGroupAuditSnapshot::of($group);
        $now = $this->clock->now();

        if ($command->name !== null && trim($command->name) !== '') {
            $group->rename(trim($command->name), $now);
        }
        if ($command->clearCurrency) {
            $group->changeCurrency(null, $now);
        } elseif ($currency !== null) {
            $group->changeCurrency($currency, $now);
        }
        $group->setCountries($countries, $now);
        $group->setPurchaseTypes($purchaseTypes, $now);
        $group->setMethods($methods, $now);

        $clientId = $group->clientId();
        $this->transactions->run(function () use ($group, $before, $clientId, $command): void {
            $this->groups->save($group);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'provider_group.configured')
                : AuditEntry::forSystem('provider_group.configured', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('provider_group', $command->groupId)
                    ->withChange($before, ProviderGroupAuditSnapshot::of($group)),
            );
        });

        return Result::ok(null);
    }

    /**
     * @param list<string> $countries
     *
     * @return array{0: string, 1: string}|null [country, other group slug]
     */
    private function firstCountryClash(ProviderGroup $group, array $countries): ?array
    {
        if ($countries === []) {
            return null;
        }

        foreach ($this->groups->forClient($group->clientId()) as $sibling) {
            if ($sibling->id() === $group->id() || $sibling->isDefault()) {
                continue;
            }
            if ($sibling->deviceType() !== $group->deviceType()) {
                continue;
            }
            foreach ($countries as $country) {
                if ($sibling->servesCountry($country)) {
                    return [$country, $sibling->slug()->value];
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $countries
     *
     * @return list<string>
     */
    private static function normaliseCountries(array $countries): array
    {
        return array_values(array_unique(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            array_filter($countries, static fn (string $c): bool => trim($c) !== ''),
        )));
    }
}
