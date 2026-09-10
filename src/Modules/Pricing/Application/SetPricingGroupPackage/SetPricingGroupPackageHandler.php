<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPricingGroupPackage;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackage;
use Gomrok\Modules\Pricing\Domain\PricingGroupPackageRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\PricingRowStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;
use ValueError;

final readonly class SetPricingGroupPackageHandler
{
    public function __construct(
        private PricingGroupPackageRepository $rows,
        private PricingGroupRepository $groups,
        private PackageDirectory $packages,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetPricingGroupPackageCommand $command): Result
    {
        try {
            $status = PricingRowStatus::from($command->status);
        } catch (ValueError) {
            return Result::err(DomainError::validation('pricing_group_package.invalid_status', 'Status must be default, override or disabled.', ['status' => $command->status]));
        }

        $group = $this->groups->findById($command->pricingGroupId);
        if ($group === null) {
            return Result::err(DomainError::notFound('pricing_group.not_found', "Pricing group {$command->pricingGroupId} was not found."));
        }

        $package = $this->packages->findById($command->packageId);
        if ($package === null || $package->clientId !== $group->clientId()) {
            return Result::err(DomainError::validation('pricing_group_package.package_not_owned', "Package {$command->packageId} does not belong to this client.", ['package_id' => $command->packageId]));
        }

        $error = PricingGroupPackage::validate($status, $command->amountMinor, $command->currency, $group->currencyCode());
        if ($error !== null) {
            return Result::err($error);
        }

        $now = $this->clock->now();
        $existing = $this->rows->find($command->pricingGroupId, $command->packageId);
        $before = $existing !== null ? PricingAuditSnapshot::groupPackage($existing) : null;

        $row = PricingGroupPackage::create(
            $command->pricingGroupId,
            $command->packageId,
            $status,
            $command->amountMinor,
            $command->currency,
            $command->nameOverride,
            $command->badgeOverride,
            $command->highlightedOverride,
            $command->displayOrder,
            $now,
        );
        if ($existing !== null) {
            $existingId = $existing->id();
            \assert($existingId !== null);
            $row->assignId($existingId);
        }

        $clientId = $group->clientId();
        $this->transactions->run(function () use ($row, $before, $clientId, $command): void {
            $this->rows->save($row);
            $rowId = $row->id();
            \assert($rowId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'pricing_group_package.set')
                : AuditEntry::forSystem('pricing_group_package.set', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('pricing_group_package', $rowId)
                    ->withChange($before, PricingAuditSnapshot::groupPackage($row))
                    ->withContext(['pricing_group_id' => $command->pricingGroupId, 'package_id' => $command->packageId]),
            );
        });

        return Result::ok(null);
    }
}
