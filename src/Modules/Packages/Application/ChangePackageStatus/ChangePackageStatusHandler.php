<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\ChangePackageStatus;

use Gomrok\Modules\Packages\Application\PackageAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Packages\Domain\PackageStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Disable / enable a package (soft, reversible). A disabled package never
 * appears in a resolved catalogue list. Idempotent.
 */
final readonly class ChangePackageStatusHandler
{
    public function __construct(
        private PackageRepository $packages,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function disable(int $packageId, ?int $actorId = null): Result
    {
        return $this->apply($packageId, PackageStatus::Disabled, $actorId);
    }

    public function enable(int $packageId, ?int $actorId = null): Result
    {
        return $this->apply($packageId, PackageStatus::Active, $actorId);
    }

    private function apply(int $packageId, PackageStatus $target, ?int $actorId): Result
    {
        $package = $this->packages->findById($packageId);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$packageId} was not found."));
        }

        if ($package->status() === $target) {
            return Result::ok(null);
        }

        $before = PackageAuditSnapshot::of($package);
        $now = $this->clock->now();
        $action = $target === PackageStatus::Disabled ? 'package.disabled' : 'package.enabled';

        if ($target === PackageStatus::Disabled) {
            $package->disable($now);
        } else {
            $package->enable($now);
        }

        $clientId = $package->clientId();
        $this->transactions->run(function () use ($package, $before, $clientId, $packageId, $action, $actorId): void {
            $this->packages->save($package);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, $action)
                : AuditEntry::forSystem($action, $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('package', $packageId)
                    ->withChange($before, PackageAuditSnapshot::of($package)),
            );
        });

        return Result::ok(null);
    }
}
