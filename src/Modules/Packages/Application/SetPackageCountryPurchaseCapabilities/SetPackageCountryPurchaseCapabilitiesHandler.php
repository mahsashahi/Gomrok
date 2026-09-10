<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\SetPackageCountryPurchaseCapabilities;

use Gomrok\Modules\Packages\Application\PackageAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageCountryPurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Sets a package's per-country purchase-type overrides. Every country must be a
 * configured market and every listed type must be in the package's global set
 * (an override narrows, never widens). Flips `synced` provider definitions to
 * `drift`.
 */
final readonly class SetPackageCountryPurchaseCapabilitiesHandler
{
    public function __construct(
        private PackageRepository $packages,
        private ReferenceCatalog $reference,
        private PackageProviderDefinitionRepository $definitions,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetPackageCountryPurchaseCapabilitiesCommand $command): Result
    {
        $package = $this->packages->findById($command->packageId);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found."));
        }

        $overrides = [];
        foreach ($command->overridesByCountry as $countryRaw => $types) {
            $country = strtoupper(trim($countryRaw));
            if (!$this->reference->countryExists($country)) {
                return Result::err(DomainError::validation('package.unknown_country', "Country '{$country}' is not a configured market.", ['country' => $country]));
            }
            if ($types === []) {
                return Result::err(DomainError::validation('package.empty_country_override', "The override for '{$country}' lists no purchase types; omit the country to inherit the global set.", ['country' => $country]));
            }

            foreach ($types as $typeValue) {
                $type = PurchaseType::tryFrom($typeValue);
                if ($type === null) {
                    return Result::err(DomainError::validation('package.unknown_purchase_type', "Unknown purchase type '{$typeValue}'.", ['purchase_type' => $typeValue]));
                }
                if (!$package->supportsPurchaseTypeGlobally($type)) {
                    return Result::err(DomainError::validation(
                        'package.override_not_in_global_set',
                        "Country '{$country}' cannot enable '{$type->value}' — the package does not support it globally.",
                        ['country' => $country, 'purchase_type' => $type->value],
                    ));
                }
                $overrides[] = new PackageCountryPurchaseCapability($country, $type);
            }
        }

        $before = PackageAuditSnapshot::of($package);
        $now = $this->clock->now();
        $package->setCountryPurchaseCapabilities($overrides, $now);

        $clientId = $package->clientId();
        $this->transactions->run(function () use ($package, $before, $clientId, $command, $now): void {
            $this->packages->save($package);
            $stale = $this->definitions->markStaleForPackage($command->packageId, $now);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'package.country_capabilities_updated')
                : AuditEntry::forSystem('package.country_capabilities_updated', $clientId);

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
