<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\UpdatePackage;

use Gomrok\Modules\Packages\Application\PackageAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Edits a package's name / description / metadata. Code, client, availability
 * and status are not touched here.
 */
final readonly class UpdatePackageHandler
{
    public function __construct(
        private PackageRepository $packages,
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
        $package->update(
            $command->name,
            $command->description,
            $command->metadata,
            $command->clearDescription,
            $command->clearMetadata,
            $this->clock->now(),
        );

        $clientId = $package->clientId();
        $this->transactions->run(function () use ($package, $before, $clientId, $command): void {
            $this->packages->save($package);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'package.updated')
                : AuditEntry::forSystem('package.updated', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('package', $command->packageId)
                    ->withChange($before, PackageAuditSnapshot::of($package)),
            );
        });

        return Result::ok(null);
    }
}
