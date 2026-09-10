<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities;

use Gomrok\Modules\Packages\Application\PackageAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackagePurchaseCapability;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Sets a package's global purchase-type set (full replace). Validates each type
 * and its trial/duration config, then flips any `synced` provider definitions of
 * the package to `drift`.
 */
final readonly class SetPackagePurchaseCapabilitiesHandler
{
    public function __construct(
        private PackageRepository $packages,
        private PackageProviderDefinitionRepository $definitions,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetPackagePurchaseCapabilitiesCommand $command): Result
    {
        $package = $this->packages->findById($command->packageId);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found."));
        }

        $seen = [];
        $capabilities = [];
        foreach ($command->capabilities as $input) {
            $type = PurchaseType::tryFrom($input->purchaseType);
            if ($type === null) {
                return Result::err(DomainError::validation('package.unknown_purchase_type', "Unknown purchase type '{$input->purchaseType}'.", ['purchase_type' => $input->purchaseType]));
            }
            if (isset($seen[$type->value])) {
                return Result::err(DomainError::validation('package.duplicate_purchase_type', "Purchase type '{$type->value}' is listed twice.", ['purchase_type' => $type->value]));
            }
            $seen[$type->value] = true;

            $error = PackagePurchaseCapability::validate($type, $input->hasTrial, $input->trialDays, $input->durationMonths);
            if ($error !== null) {
                return Result::err($error);
            }

            $capabilities[] = PackagePurchaseCapability::of($type, $input->hasTrial, $input->trialDays, $input->durationMonths);
        }

        $before = PackageAuditSnapshot::of($package);
        $now = $this->clock->now();
        $package->setPurchaseCapabilities($capabilities, $now);

        $clientId = $package->clientId();
        $this->transactions->run(function () use ($package, $before, $clientId, $command, $now): void {
            $this->packages->save($package);
            $stale = $this->definitions->markStaleForPackage($command->packageId, $now);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'package.capabilities_updated')
                : AuditEntry::forSystem('package.capabilities_updated', $clientId);

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
