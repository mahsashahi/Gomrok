<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Application\Audit;

use Gomrok\Shared\Application\Audit\AuditActor;
use Gomrok\Shared\Application\Audit\AuditEntry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    #[Test]
    public function adminUserFactorySetsActorFields(): void
    {
        $entry = AuditEntry::forAdminUser(5, 12, 'provider_config.updated');

        self::assertSame(AuditActor::AdminUser, $entry->actorType);
        self::assertSame(5, $entry->actorId);
        self::assertSame(12, $entry->clientId);
        self::assertSame('provider_config.updated', $entry->action);
    }

    #[Test]
    public function clientFactoryUsesTheClientAsBothActorAndScope(): void
    {
        $entry = AuditEntry::forClient(12, 'payment.created');

        self::assertSame(AuditActor::Client, $entry->actorType);
        self::assertSame(12, $entry->actorId);
        self::assertSame(12, $entry->clientId);
    }

    #[Test]
    public function systemFactoryHasNoActorId(): void
    {
        $entry = AuditEntry::forSystem('subscription.reconciled', 9);

        self::assertSame(AuditActor::System, $entry->actorType);
        self::assertNull($entry->actorId);
        self::assertSame(9, $entry->clientId);
    }

    #[Test]
    public function withersProduceAFullyPopulatedImmutableCopy(): void
    {
        $base = AuditEntry::forAdminUser(5, 12, 'pricing.updated');

        $entry = $base
            ->withTarget('price_rule', 77)
            ->withChange(['amount_minor' => 1000], ['amount_minor' => 1200])
            ->withContext(['reason' => 'promo end'])
            ->withRequest('corr-1', '203.0.113.4', 'curl/8');

        self::assertNull($base->targetType);
        self::assertSame('price_rule', $entry->targetType);
        self::assertSame(77, $entry->targetId);
        self::assertSame(['amount_minor' => 1000], $entry->before);
        self::assertSame(['amount_minor' => 1200], $entry->after);
        self::assertSame(['reason' => 'promo end'], $entry->context);
        self::assertSame('corr-1', $entry->correlationId);
        self::assertSame('203.0.113.4', $entry->ip);
        self::assertSame('curl/8', $entry->userAgent);
    }
}
