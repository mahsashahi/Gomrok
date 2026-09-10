<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\UpdatePackage;

use Gomrok\Modules\Packages\Application\PackageAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Edits a package's name / description / metadata and its display fields
 * (`badge` / `highlighted` / `clientPackageId`). Flips `synced` provider
 * definitions of the package to `drift`. Code, client, availability, purchase
 * capabilities and status are not touched here.
 */
final readonly class UpdatePackageHandler
{
    public function __construct(
        private PackageRepository $packages,
        private PackageProviderDefinitionRepository $definitions,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(UpdatePackageCommand $command): Result
    {
        $package = $this->packages->findById($command->packageId);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found."));
        }

        if ($command->name !== null && trim($command->name) === '') {
            return Result::err(DomainError::validation('package.name_required', 'A name cannot be blank.'));
        }

        $before = PackageAuditSnapshot::of($package);
        $now = $this->clock->now();
        $package->update(
            $command->name,
            $command->description,
            $command->metadata,
            $command->clearDescription,
            $command->clearMetadata,
            $now,
        );
        $package->updateDisplay(
            $command->badge,
            $command->highlighted,
            $command->clientPackageId,
            $command->clearBadge,
            $command->clearClientPackageId,
            $now,
        );

        $clientId = $package->clientId();
        $this->transactions->run(function () use ($package, $before, $clientId, $command, $now): void {
            $this->packages->save($package);
            $stale = $this->definitions->markStaleForPackage($command->packageId, $now);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'package.updated')
                : AuditEntry::forSystem('package.updated', $clientId);

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
