<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\SetPackageAvailability;

use Gomrok\Modules\Packages\Application\PackageAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Sets a package's country / currency / method / provider-account availability.
 * Validates every value; every provider account must belong to the package's
 * client.
 */
final readonly class SetPackageAvailabilityHandler
{
    public function __construct(
        private PackageRepository $packages,
        private ReferenceCatalog $reference,
        private ProviderAccountDirectory $providerAccounts,
        private PackageProviderDefinitionRepository $definitions,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetPackageAvailabilityCommand $command): Result
    {
        $package = $this->packages->findById($command->packageId);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found."));
        }

        foreach ($command->countries as $country) {
            if (!$this->reference->countryExists($country)) {
                return Result::err(DomainError::validation('package.unknown_country', "Country '{$country}' is not a configured market.", ['country' => $country]));
            }
        }
        foreach ($command->currencies as $currency) {
            if (!$this->reference->currencyExists($currency)) {
                return Result::err(DomainError::validation('package.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
            }
        }

        $methods = [];
        foreach ($command->methods as $value) {
            $method = PaymentMethod::tryFrom($value);
            if ($method === null) {
                return Result::err(DomainError::validation('package.unknown_method', "Unknown payment method '{$value}'.", ['method' => $value]));
            }
            $methods[] = $method;
        }

        if ($command->providerAccountIds !== []) {
            $owned = [];
            foreach ($this->providerAccounts->forClient($package->clientId()) as $summary) {
                $owned[$summary->id] = true;
            }
            foreach ($command->providerAccountIds as $accountId) {
                if (!isset($owned[$accountId])) {
                    return Result::err(DomainError::validation(
                        'package.account_not_owned',
                        "Provider account {$accountId} does not belong to this client.",
                        ['provider_account_id' => $accountId],
                    ));
                }
            }
        }

        $before = PackageAuditSnapshot::of($package);
        $now = $this->clock->now();
        $package->setAvailability(
            $command->countries,
            $command->currencies,
            $methods,
            $command->providerAccountIds,
            $now,
        );

        $clientId = $package->clientId();
        $this->transactions->run(function () use ($package, $before, $clientId, $command, $now): void {
            $this->packages->save($package);
            $stale = $this->definitions->markStaleForPackage($command->packageId, $now);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'package.availability_updated')
                : AuditEntry::forSystem('package.availability_updated', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('package', $command->packageId)
                    ->withChange($before, PackageAuditSnapshot::of($package))
                    ->withContext(['provider_definitions_drifted' => $stale]),
            );
        });

        return Result::ok(null);
    }
}
