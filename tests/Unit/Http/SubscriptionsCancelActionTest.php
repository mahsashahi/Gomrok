<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\SubscriptionsCancelAction;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Application\CancelSubscription\CancelSubscriptionHandler;
use Gomrok\Modules\Subscriptions\Application\ResolveSubscriptionActionContext;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptDirectory;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Gomrok\Tests\Support\InMemorySubscriptionDirectory;
use Gomrok\Tests\Support\InMemorySubscriptionEventRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\RecordingDomainEventDispatcher;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SubscriptionsCancelActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    #[Test]
    public function cancelsAnActiveSubscription(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $attempts = new InMemoryCheckoutAttemptRepository();
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $now);
        $attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $subscriptions = new InMemorySubscriptionRepository();
        $subscription = Subscription::create(self::CLIENT, 'user-1', $attemptId, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $now);
        $subscriptions->save($subscription);
        $subscriptionId = $subscription->id();
        \assert($subscriptionId !== null);

        $gatewayReferences = new InMemoryGatewayReferenceRepository();
        $gatewayReferences->save(GatewayReference::forSubscription(self::CLIENT, self::PROVIDER_ACCOUNT, GatewayReferenceType::Subscription, 'sub_test_1', $subscriptionId, $now));

        $accounts = (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'account-main', 'stripe');
        $adapter = new FakePaymentProviderPort();

        $context = new ResolveSubscriptionActionContext(
            $accounts,
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $adapter),
            $gatewayReferences,
        );

        $handler = new CancelSubscriptionHandler(
            $subscriptions,
            new InMemorySubscriptionEventRepository(),
            $context,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        $clientContext = new ClientContext();
        $clientContext->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));
        $idempotency = new IdempotencyContext();

        $action = new SubscriptionsCancelAction(
            $clientContext,
            new InMemoryCheckoutAttemptDirectory($attempts),
            new InMemorySubscriptionDirectory($subscriptions),
            $handler,
            $idempotency,
            new JsonResponder(),
        );

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('POST', "/api/v1/subscriptions/{$attemptId}/cancel"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $attemptId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{status: string} $body */
        self::assertSame('cancelled', $body['status']);
        self::assertSame('subscription', $idempotency->targetType());
        self::assertSame($subscriptionId, $idempotency->targetId());
    }

    #[Test]
    public function anUnknownIdIs404(): void
    {
        $attempts = new InMemoryCheckoutAttemptRepository();
        $subscriptions = new InMemorySubscriptionRepository();

        $context = new ResolveSubscriptionActionContext(
            new StubProviderAccountDirectory(),
            new ProviderCapabilityResolver(InMemoryProviderTypeDeclarations::withKnownProviders()),
            new StubProviderAdapterFactory(),
            new InMemoryGatewayReferenceRepository(),
        );
        $handler = new CancelSubscriptionHandler(
            $subscriptions,
            new InMemorySubscriptionEventRepository(),
            $context,
            new RecordingAuditLogWriter(),
            new SynchronousTransactions(),
            new FrozenClock('2026-09-14T12:00:00+00:00'),
            new RecordingDomainEventDispatcher(),
        );

        $clientContext = new ClientContext();
        $clientContext->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));

        $action = new SubscriptionsCancelAction(
            $clientContext,
            new InMemoryCheckoutAttemptDirectory($attempts),
            new InMemorySubscriptionDirectory($subscriptions),
            $handler,
            new IdempotencyContext(),
            new JsonResponder(),
        );

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/subscriptions/999/cancel'),
            (new ResponseFactory())->createResponse(),
            ['id' => '999'],
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
