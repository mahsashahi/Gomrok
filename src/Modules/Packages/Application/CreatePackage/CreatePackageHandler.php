<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\CreatePackage;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageAuditSnapshot;
use Gomrok\Modules\Packages\Domain\Package;
use Gomrok\Modules\Packages\Domain\PackageCode;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Creates a package: validates the client, code format + per-client uniqueness,
 * and the metadata shape; persists; audits.
 */
final readonly class CreatePackageHandler
{
    public function __construct(
        private PackageRepository $packages,
        private ClientDirectory $clients,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreatePackageCommand $command): Result
    {
        if (!PackageCode::isValid($command->code)) {
            return Result::err(PackageCode::error($command->code));
        }

        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('package.name_required', 'A name is required.'));
        }

        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }
        if (!$client->isActive()) {
            return Result::err(DomainError::forbidden('client.disabled', 'Cannot add a package to a disabled client.'));
        }

        if ($this->packages->existsForClientWithCode($command->clientId, $command->code)) {
            return Result::err(DomainError::conflict(
                'package.code_taken',
                "This client already has a package '{$command->code}'.",
                ['code' => $command->code],
            ));
        }

        $now = $this->clock->now();
        $package = Package::create(
            $command->clientId,
            PackageCode::of($command->code),
            $name,
            $command->description,
            $command->metadata,
            $now,
        );

        $this->transactions->run(function () use ($package, $command): void {
            $this->packages->save($package);
            $packageId = $package->id();
            \assert($packageId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'package.created')
                : AuditEntry::forSystem('package.created', $command->clientId);

            $this->audit->record(
                $entry
                    ->withTarget('package', $packageId)
                    ->withChange(null, PackageAuditSnapshot::of($package)),
            );
        });

        $packageId = $package->id();
        \assert($packageId !== null);

        return Result::ok(new CreatePackageResult($packageId, $command->code));
    }
}
