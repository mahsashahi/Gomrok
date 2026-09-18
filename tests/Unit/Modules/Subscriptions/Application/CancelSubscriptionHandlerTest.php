<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Subscriptions\Application;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\CancelSubscription\CancelSubscriptionCommand;
use Gomrok\Modules\Subscriptions\Application\CancelSubscription\CancelSubscriptionHandler;
use Gomrok\Modules\Subscriptions\Application\CancelSubscription\CancelSubscriptionResult;
use Gomrok\Modules\Subscriptions\Application\ResolveSubscriptionActionContext;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\InMemorySubscriptionEventRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CancelSubscriptionHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryCheckoutAttemptRepository $attempts;
    private InMemorySubscriptionRepository $subscriptions;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private FakePaymentProviderPort $adapter;
    private CancelSubscriptionHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $this->attempts = new InMemoryCheckoutAttemptRepository();
        $this->subscriptions = new InMemorySubscriptionRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->adapter = new FakePaymentProviderPort();
        $this->handler = $this->buildHandler('stripe');
    }

    #[Test]
    public function cancelsAnActiveSubscriptionUsingItsRealSubscriptionReference(): void
    {
        $subscriptionId = $this->seedSubscription(withSubscriptionReference: true);

        $result = $this->handler->handle(new CancelSubscriptionCommand(self::CLIENT, $subscriptionId));

        self::assertTrue($result->isOk());
        $value = $result->value();
        \assert($value instanceof CancelSubscriptionResult);
        self::assertSame('cancelled', $value->status);
        self::assertSame('sub_test_1', $this->adapter->lastCancelSubscriptionReference);

        $subscription = $this->subscriptions->findById($subscriptionId);
        self::assertNotNull($subscription);
        self::assertSame(SubscriptionStatus::Cancelled, $subscription->status());
    }

    #[Test]
    public function fallsBackToTheCheckoutSessionReferenceWhenNoRealSubscriptionReferenceExists(): void
    {
        $subscriptionId = $this->seedSubscription(withSubscriptionReference: false);

        $result = $this->handler->handle(new CancelSubscriptionCommand(self::CLIENT, $subscriptionId));

        self::assertTrue($result->isOk());
        self::assertSame('cs_test_1', $this->adapter->lastCancelSubscriptionReference);
    }

    #[Test]
    public function rejectsAnAlreadyCancelledSubscription(): void
    {
        $subscriptionId = $this->seedSubscription(withSubscriptionReference: true);
        $this->handler->handle(new CancelSubscriptionCommand(self::CLIENT, $subscriptionId));

        $result = $this->handler->handle(new CancelSubscriptionCommand(self::CLIENT, $subscriptionId));

        self::assertTrue($result->isErr());
        self::assertSame('subscription.already_cancelled', $result->error()->code);
    }

    #[Test]
    public function rejectsCancelWhenTheProviderDoesNotSupportIt(): void
    {
        $this->handler = $this->buildHandler('ziraat');
        $subscriptionId = $this->seedSubscription(withSubscriptionReference: true);

        $result = $this->handler->handle(new CancelSubscriptionCommand(self::CLIENT, $subscriptionId));

        self::assertTrue($result->isErr());
        self::assertSame('subscription.cancel_not_supported', $result->error()->code);
        self::assertNull($this->adapter->lastCancelSubscriptionReference);
    }

    #[Test]
    public function anUnknownSubscriptionIsNotFound(): void
    {
        $result = $this->handler->handle(new CancelSubscriptionCommand(self::CLIENT, 999));

        self::assertTrue($result->isErr());
        self::assertSame('subscription.not_found', $result->error()->code);
    }

    #[Test]
    public function aRealSubscriptionOwnedByAnotherClientIsNotFound(): void
    {
        $subscriptionId = $this->seedSubscription(withSubscriptionReference: true);

        $result = $this->handler->handle(new CancelSubscriptionCommand(self::CLIENT + 1, $subscriptionId));

        self::assertTrue($result->isErr());
        self::assertSame('subscription.not_found', $result->error()->code);
        self::assertNull($this->adapter->lastCancelSubscriptionReference);
    }

    private function buildHandler(string $providerTypeCode): CancelSubscriptionHandler
    {
        $accounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'account-main', $providerTypeCode);

        $context = new ResolveSubscriptionActionContext(
            $accounts,
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter),
            $this->gatewayReferences,
        );

        return new CancelSubscriptionHandler(
            $this->subscriptions,
            new InMemorySubscriptionEventRepository(),
            $context,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );
    }

    private function seedSubscription(bool $withSubscriptionReference): int
    {
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $this->now);
        $this->attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $subscription = Subscription::create(self::CLIENT, 'user-1', $attemptId, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $this->now);
        $this->subscriptions->save($subscription);
        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        $this->gatewayReferences->save(GatewayReference::forCheckoutAttempt(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::CheckoutSession, 'cs_test_1', $attemptId, $this->now));
        if ($withSubscriptionReference) {
            $this->gatewayReferences->save(GatewayReference::forSubscription(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::Subscription, 'sub_test_1', $subscriptionId, $this->now));
        }

        return $subscriptionId;
    }
}
