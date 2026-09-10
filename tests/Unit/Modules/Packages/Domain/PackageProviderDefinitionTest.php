<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Packages\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Packages\Domain\PackageProviderDefinition;
use Gomrok\Modules\Packages\Domain\PackageProviderSyncState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageProviderDefinitionTest extends TestCase
{
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function linkingWithoutARemoteIdIsNotCreated(): void
    {
        $definition = PackageProviderDefinition::link(1, 2, 'Pro (Stripe)', null, $this->now);

        self::assertSame(PackageProviderSyncState::NotCreated, $definition->syncState());
        self::assertNull($definition->remoteId());
        self::assertNull($definition->lastSyncedAt());
    }

    #[Test]
    public function linkingWithARemoteIdIsSynced(): void
    {
        $definition = PackageProviderDefinition::link(1, 2, 'Pro', 'prod_ABC', $this->now);

        self::assertSame(PackageProviderSyncState::Synced, $definition->syncState());
        self::assertSame('prod_ABC', $definition->remoteId());
        self::assertEquals($this->now, $definition->lastSyncedAt());
    }

    #[Test]
    public function markDriftOnlyAffectsSyncedDefinitions(): void
    {
        $notCreated = PackageProviderDefinition::link(1, 2, null, null, $this->now);
        self::assertFalse($notCreated->markDrift($this->now));
        self::assertSame(PackageProviderSyncState::NotCreated, $notCreated->syncState());

        $synced = PackageProviderDefinition::link(1, 3, null, 'prod_X', $this->now);
        self::assertTrue($synced->markDrift($this->now));
        self::assertSame(PackageProviderSyncState::Drift, $synced->syncState());
        self::assertFalse($synced->markDrift($this->now));
    }

    #[Test]
    public function markSyncedFromDriftRestoresSyncedAndClearsError(): void
    {
        $definition = PackageProviderDefinition::link(1, 2, null, 'prod_X', $this->now);
        $definition->recordError('provider rejected the update', $this->now);
        $definition->markDrift($this->now);

        $definition->markSynced('prod_Y', $this->now);

        self::assertSame(PackageProviderSyncState::Synced, $definition->syncState());
        self::assertSame('prod_Y', $definition->remoteId());
        self::assertNull($definition->lastError());
    }

    #[Test]
    public function markNotNeededDropsTheRemoteId(): void
    {
        $definition = PackageProviderDefinition::link(1, 2, null, 'prod_X', $this->now);

        $definition->markNotNeeded($this->now);

        self::assertSame(PackageProviderSyncState::NotNeeded, $definition->syncState());
        self::assertNull($definition->remoteId());
        self::assertFalse($definition->markDrift($this->now));
    }
}
