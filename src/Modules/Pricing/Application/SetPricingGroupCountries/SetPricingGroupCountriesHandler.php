<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPricingGroupCountries;

use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

final readonly class SetPricingGroupCountriesHandler
{
    public function __construct(
        private PricingGroupRepository $groups,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetPricingGroupCountriesCommand $command): Result
    {
        $group = $this->groups->findById($command->groupId);
        if ($group === null) {
            return Result::err(DomainError::notFound('pricing_group.not_found', "Pricing group {$command->groupId} was not found."));
        }

        $countries = array_values(array_unique(array_map(
            static fn (string $c): string => strtoupper(trim($c)),
            array_filter($command->countries, static fn (string $c): bool => trim($c) !== ''),
        )));

        if ($group->isDefault() && $countries !== []) {
            return Result::err(DomainError::validation('pricing_group.default_takes_no_countries', 'The default pricing group covers every country no other group claims; it cannot list countries.'));
        }

        foreach ($countries as $country) {
            if (!$this->reference->countryExists($country)) {
                return Result::err(DomainError::validation('pricing_group.unknown_country', "Country '{$country}' is not a configured market.", ['country' => $country]));
            }
        }

        $before = PricingAuditSnapshot::group($group);
        $group->setCountries($countries, $this->clock->now());

        $clientId = $group->clientId();
        $this->transactions->run(function () use ($group, $before, $clientId, $command): void {
            $this->groups->save($group);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'pricing_group.countries_updated')
                : AuditEntry::forSystem('pricing_group.countries_updated', $clientId);

            $this->audit->record($entry->withTarget('pricing_group', $command->groupId)->withChange($before, PricingAuditSnapshot::group($group)));
        });

        return Result::ok(null);
    }
}
