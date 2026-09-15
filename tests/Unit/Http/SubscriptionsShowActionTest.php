<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\SubscriptionsShowAction;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptDirectory;
use Gomrok\Tests\Support\InMemoryCheckoutAttemptRepository;
use Gomrok\Tests\Support\InMemorySubscriptionDirectory;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class SubscriptionsShowActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    #[Test]
    public function reportsTheCheckoutAttemptsOwnStateBeforeASubscriptionExists(): void
    {
        $now = new DateTimeImmutable('2026-09-14T12:00:00+00:00');
        $attempts = new InMemoryCheckoutAttemptRepository();
        $attempt = CheckoutAttempt::start(self::CLIENT, 'user-1', 'order-1', self::PACKAGE, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $now);
        $attempts->save($attempt);
        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        $action = $this->action($attempts, new InMemorySubscriptionRepository());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', "/api/v1/subscriptions/{$attemptId}"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $attemptId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{subscription_id: mixed, status: string} $body */
        self::assertNull($body['subscription_id']);
        self::assertSame('started', $body['status']);
    }

    #[Test]
    public function reportsTheRealSubscriptionOnceOneExists(): void
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

        $action = $this->action($attempts, $subscriptions);

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', "/api/v1/subscriptions/{$attemptId}"),
            (new ResponseFactory())->createResponse(),
            ['id' => (string) $attemptId],
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{subscription_id: int, status: string} $body */
        self::assertSame($subscription->id(), $body['subscription_id']);
        self::assertSame('active', $body['status']);
    }

    #[Test]
    public function anUnknownIdIs404(): void
    {
        $action = $this->action(new InMemoryCheckoutAttemptRepository(), new InMemorySubscriptionRepository());

        $response = $action->__invoke(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/subscriptions/999'),
            (new ResponseFactory())->createResponse(),
            ['id' => '999'],
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function action(InMemoryCheckoutAttemptRepository $attempts, InMemorySubscriptionRepository $subscriptions): SubscriptionsShowAction
    {
        $context = new ClientContext();
        $context->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'test'));

        return new SubscriptionsShowAction($context, new InMemoryCheckoutAttemptDirectory($attempts), new InMemorySubscriptionDirectory($subscriptions), new JsonResponder());
    }
}
