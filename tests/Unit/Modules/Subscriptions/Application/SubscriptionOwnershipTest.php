<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Subscriptions\Application;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Exercises CLAUDE.md's Subscription Ownership Model queries directly
 * (Phase 26 exit criterion): "given a gateway subscription id, which Gomrok
 * subscription ... does it belong to" and "given a client user ID, which
 * active subscriptions does the user have".
 */
final class SubscriptionOwnershipTest extends TestCase
{
    private const CLIENT = 7;
    private const OTHER_CLIENT = 8;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    #[Test]
    public function aGatewaySubscriptionIdResolvesToItsInternalSubscription(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $subscriptions = new InMemorySubscriptionRepository();
        $subscription = Subscription::create(self::CLIENT, 'user-1', 100, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $now);
        $subscriptions->save($subscription);
        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $gatewayReferences->save(GatewayReference::forSubscription(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::Subscription, 'sub_abc123', $subscriptionId, $now));

        $reference = $gatewayReferences->findByReference(self::PROVIDER_ACCOUNT, GatewayReferenceType::Subscription, 'sub_abc123');
        self::assertNotNull($reference);
        self::assertSame($subscriptionId, $reference->subscriptionId);

        $resolved = $subscriptions->findById($reference->subscriptionId);
        self::assertNotNull($resolved);
        self::assertSame(self::CLIENT, $resolved->clientId());
        self::assertSame('user-1', $resolved->clientUserRef());
    }

    #[Test]
    public function aClientUserResolvesToTheirSubscriptionsScopedToTheirOwnClient(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $subscriptions = new InMemorySubscriptionRepository();

        $mine = Subscription::create(self::CLIENT, 'user-1', 100, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $now);
        $subscriptions->save($mine);

        $otherUser = Subscription::create(self::CLIENT, 'user-2', 101, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $now);
        $subscriptions->save($otherUser);

        $otherClientSameRef = Subscription::create(self::OTHER_CLIENT, 'user-1', 102, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $now);
        $subscriptions->save($otherClientSameRef);

        $results = $subscriptions->forClientUser(self::CLIENT, 'user-1');

        self::assertCount(1, $results);
        self::assertSame(self::CLIENT, $results[0]->clientId());
        self::assertSame('user-1', $results[0]->clientUserRef());
    }
}
