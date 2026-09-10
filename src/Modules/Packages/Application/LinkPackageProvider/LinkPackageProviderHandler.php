<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\LinkPackageProvider;

use Gomrok\Modules\Packages\Application\PackageProviderDefinitionAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinition;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Links a package to one of its client's provider accounts on the provider
 * side. Creates the `package_provider_definitions` row (or updates it). The
 * provider-API product creation is deferred to the adapter phases; a manually
 * entered `remoteId` is accepted here and marks the row `synced`.
 */
final readonly class LinkPackageProviderHandler
{
    public function __construct(
        private PackageRepository $packages,
        private PackageProviderDefinitionRepository $definitions,
        private ProviderAccountDirectory $providerAccounts,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(LinkPackageProviderCommand $command): Result
    {
        $package = $this->packages->findById($command->packageId);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found."));
        }

        $owned = false;
        foreach ($this->providerAccounts->forClient($package->clientId()) as $summary) {
            if ($summary->id === $command->providerAccountId) {
                $owned = true;

                break;
            }
        }
        if (!$owned) {
            return Result::err(DomainError::validation(
                'package.account_not_owned',
                "Provider account {$command->providerAccountId} does not belong to this client.",
                ['provider_account_id' => $command->providerAccountId],
            ));
        }

        $now = $this->clock->now();
        $existing = $this->definitions->findByPackageAndAccount($command->packageId, $command->providerAccountId);

        if ($existing === null) {
            $definition = PackageProviderDefinition::link(
                $command->packageId,
                $command->providerAccountId,
                $command->providerSideName,
                $command->remoteId,
                $now,
            );
            $before = null;
        } else {
            $before = PackageProviderDefinitionAuditSnapshot::of($existing);
            $definition = $existing;
            $definition->rename($command->providerSideName, $now);
            if ($command->remoteId !== null && trim($command->remoteId) !== '') {
                $definition->markSynced($command->remoteId, $now);
            }
        }

        $clientId = $package->clientId();
        $this->transactions->run(function () use ($definition, $before, $clientId, $command): void {
            $this->definitions->save($definition);
            $definitionId = $definition->id();
            \assert($definitionId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'package.provider_linked')
                : AuditEntry::forSystem('package.provider_linked', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('package_provider_definition', $definitionId)
                    ->withChange($before, PackageProviderDefinitionAuditSnapshot::of($definition))
                    ->withContext(['package_id' => $command->packageId]),
            );
        });

        return Result::ok(null);
    }
}
