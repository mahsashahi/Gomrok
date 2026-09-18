<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Reconciliation\Domain;

use DateTimeImmutable;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReconciliationFindingTest extends TestCase
{
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-17T12:00:00+00:00');
    }

    #[Test]
    public function detectStartsUnresolvedWithNoId(): void
    {
        $finding = ReconciliationFinding::detect(1, ReconciliationTargetType::Payment, 42, 'pending', 'paid', 'paid', $this->now());

        self::assertNull($finding->id());
        self::assertFalse($finding->isResolved());
        self::assertNull($finding->resolvedAt());
        self::assertNull($finding->resolvedBy());
        self::assertSame(1, $finding->clientId());
        self::assertSame(ReconciliationTargetType::Payment, $finding->targetType());
        self::assertSame(42, $finding->targetId());
        self::assertSame('pending', $finding->localStatus());
        self::assertSame('paid', $finding->providerStatusRaw());
        self::assertSame('paid', $finding->mappedProviderStatus());
    }

    #[Test]
    public function detectAllowsANullMappedStatusForAnUnknownProviderStatus(): void
    {
        $finding = ReconciliationFinding::detect(1, ReconciliationTargetType::Subscription, 7, 'active', 'some_unmapped_status', null, $this->now());

        self::assertNull($finding->mappedProviderStatus());
    }

    #[Test]
    public function markResolvedSetsResolvedAtAndResolvedBy(): void
    {
        $finding = ReconciliationFinding::detect(1, ReconciliationTargetType::Payment, 42, 'pending', 'paid', 'paid', $this->now());

        $resolvedAt = $this->now()->modify('+1 hour');
        $finding->markResolved(5, $resolvedAt);

        self::assertTrue($finding->isResolved());
        self::assertSame($resolvedAt, $finding->resolvedAt());
        self::assertSame(5, $finding->resolvedBy());
    }

    #[Test]
    public function assignIdSetsTheIdOnce(): void
    {
        $finding = ReconciliationFinding::detect(1, ReconciliationTargetType::Payment, 42, 'pending', 'paid', 'paid', $this->now());
        $finding->assignId(9);

        self::assertSame(9, $finding->id());
    }

    #[Test]
    public function fromStorageRoundTripsEveryField(): void
    {
        $detectedAt = $this->now();
        $resolvedAt = $this->now()->modify('+1 hour');

        $finding = ReconciliationFinding::fromStorage(
            9,
            1,
            ReconciliationTargetType::Subscription,
            42,
            'active',
            'canceled',
            'canceled',
            $detectedAt,
            $resolvedAt,
            5,
            $detectedAt,
        );

        self::assertSame(9, $finding->id());
        self::assertTrue($finding->isResolved());
        self::assertSame($resolvedAt, $finding->resolvedAt());
        self::assertSame(5, $finding->resolvedBy());
        self::assertSame($detectedAt, $finding->createdAt());
    }
}
