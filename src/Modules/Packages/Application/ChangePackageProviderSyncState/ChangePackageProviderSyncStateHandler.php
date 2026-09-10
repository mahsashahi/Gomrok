<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\ChangePackageProviderSyncState;

use Gomrok\Modules\Packages\Application\PackageProviderDefinitionAuditSnapshot;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinitionRepository;
use Gomrok\Modules\Packages\Domain\PackageRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * The manual state transitions on a {@see \Gomrok\Modules\Packages\Domain\PackageProviderDefinition}:
 * attach a remote id (`→ synced`) or declare that this provider needs no remote
 * product (`→ not_needed`). The provider-API-driven creation path lands with
 * each adapter (Phases 21–23).
 */
final readonly class ChangePackageProviderSyncStateHandler
{
    public function __construct(
        private PackageProviderDefinitionRepository $definitions,
        private PackageRepository $packages,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function markSynced(int $definitionId, string $remoteId, ?int $actorId = null): Result
    {
        if (trim($remoteId) === '') {
            return Result::err(DomainError::validation('package.remote_id_required', 'A remote id is required to mark a definition synced.'));
        }

        return $this->apply($definitionId, 'package.provider_synced', $actorId, function ($definition) use ($remoteId): void {
            $definition->markSynced($remoteId, $this->clock->now());
        });
    }

    public function markNotNeeded(int $definitionId, ?int $actorId = null): Result
    {
        return $this->apply($definitionId, 'package.provider_not_needed', $actorId, function ($definition): void {
            $definition->markNotNeeded($this->clock->now());
        });
    }

    /**
     * @param callable(\Gomrok\Modules\Packages\Domain\PackageProviderDefinition): void $mutate
     */
    private function apply(int $definitionId, string $action, ?int $actorId, callable $mutate): Result
    {
        $definition = $this->definitions->findById($definitionId);
        if ($definition === null) {
            return Result::err(DomainError::notFound('package.provider_definition_not_found', "Package-provider definition {$definitionId} was not found."));
        }

        $package = $this->packages->findById($definition->packageId());
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$definition->packageId()} was not found."));
        }

        $before = PackageProviderDefinitionAuditSnapshot::of($definition);
        $mutate($definition);

        $clientId = $package->clientId();
        $packageId = $definition->packageId();
        $this->transactions->run(function () use ($definition, $before, $clientId, $packageId, $definitionId, $action, $actorId): void {
            $this->definitions->save($definition);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, $action)
                : AuditEntry::forSystem($action, $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('package_provider_definition', $definitionId)
                    ->withChange($before, PackageProviderDefinitionAuditSnapshot::of($definition))
                    ->withContext(['package_id' => $packageId]),
            );
        });

        return Result::ok(null);
    }
}
