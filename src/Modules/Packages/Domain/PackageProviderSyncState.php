<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Domain;

/**
 * Where a {@see PackageProviderDefinition} stands relative to the provider side
 * (Phase 12 Q3).
 *
 * - `NotCreated` — no remote product/plan yet.
 * - `Synced`     — `remoteId` set and believed current.
 * - `Drift`      — the local package changed since the last sync; needs re-push.
 * - `NotNeeded`  — the provider has no product model (e.g. a Ziraat redirect / one-time flow).
 */
enum PackageProviderSyncState: string
{
    case NotCreated = 'not_created';
    case Synced = 'synced';
    case Drift = 'drift';
    case NotNeeded = 'not_needed';
}
