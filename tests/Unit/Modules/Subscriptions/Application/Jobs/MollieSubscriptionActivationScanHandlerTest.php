<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Subscriptions\Application\Jobs;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\EncryptedSecret;
use Gomrok\Modules\Providers\Domain\EndpointKind;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccount;
use Gomrok\Modules\Providers\Domain\ProviderAccountEndpoint;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\ProviderAccountSlug;
use Gomrok\Modules\Subscriptions\Application\Jobs\MollieSubscriptionActivationScanHandler;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Shared\Domain\Jobs\Job;
use Gomrok\Tests\Support\FakePaymentProviderPort;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryGatewayReferenceRepository;
use Gomrok\Tests\Support\InMemoryPackageDirectory;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryProviderAccountRepository;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\StubProviderAdapterFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MollieSubscriptionActivationScanHandlerTest extends TestCase
{
    private const CLIENT = 1;
    private const PROVIDER_ACCOUNT = 1;
    private const CHECKOUT_ATTEMPT = 100;

    private InMemorySubscriptionRepository $subscriptions;
    private InMemoryGatewayReferenceRepository $gatewayReferences;
    private InMemoryProviderAccountRepository $providerAccounts;
    private FakePaymentProviderPort $adapter;
    private FrozenClock $clock;
    private MollieSubscriptionActivationScanHandler $handler;

    protected function setUp(): void
    {
        $this->subscriptions = new InMemorySubscriptionRepository();
        $this->gatewayReferences = new InMemoryGatewayReferenceRepository();
        $this->providerAccounts = new InMemoryProviderAccountRepository();
        $this->adapter = new FakePaymentProviderPort();
        $this->clock = new FrozenClock('2026-09-17T12:00:00+00:00');

        $account = ProviderAccount::register(
            clientId: self::CLIENT,
            providerTypeId: 1,
            slug: ProviderAccountSlug::of('mollie-test'),
            name: 'Mollie Test',
            mode: ProviderAccountMode::Test,
            publicKey: null,
            secret: new EncryptedSecret('ciphertext', 'abcd'),
            countryCodes: ['NL'],
            methods: [PaymentMethod::Card],
            now: $this->clock->now(),
        );
        $account->addEndpoint(ProviderAccountEndpoint::create(EndpointKind::Webhook, 'whk_1', null), $this->clock->now());
        $this->providerAccounts->save($account);
        self::assertSame(self::PROVIDER_ACCOUNT, $account->id());

        $this->handler = new MollieSubscriptionActivationScanHandler(
            $this->subscriptions,
            $this->gatewayReferences,
            $this->providerAccounts,
            new InMemoryPackageDirectory(new InMemoryPackageRepository()),
            (new StubProviderAdapterFactory())->add(self::PROVIDER_ACCOUNT, $this->adapter),
            $this->clock,
            'https://gomrok.example',
        );
    }

    private function seedSubscription(): int
    {
        $subscription = Subscription::create(
            self::CLIENT,
            'user-1',
            self::CHECKOUT_ATTEMPT,
            42,
            self::PROVIDER_ACCOUNT,
            'EUR',
            999,
            PaymentMethod::Card,
            SubscriptionInterval::Monthly,
            false,
            null,
            $this->clock->now(),
        );
        $this->subscriptions->save($subscription);
        $id = $subscription->id();
        \assert($id !== null);
        $this->subscriptions->markMollieAccount(self::PROVIDER_ACCOUNT);

        return $id;
    }

    #[Test]
    public function exposesItsTypeAndRecurrence(): void
    {
        self::assertSame('mollie_subscription_activation_scan', $this->handler->type());
        self::assertSame(10, $this->handler->recurrenceIntervalMinutes());
    }

    #[Test]
    public function activatesASubscriptionWithAKnownMollieCustomerIdAndRecordsTheGatewayReference(): void
    {
        $subscriptionId = $this->seedSubscription();
        $this->gatewayReferences->save(GatewayReference::forCheckoutAttempt(
            self::CLIENT,
            self::PROVIDER_ACCOUNT,
            GatewayReferenceType::Customer,
            'cst_1',
            self::CHECKOUT_ATTEMPT,
            $this->clock->now(),
        ));

        $job = Job::schedule('mollie_subscription_activation_scan', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertTrue($result->success);
        self::assertSame(['scanned' => 1, 'activated' => 1, 'skipped' => 0], $result->summary);
        self::assertSame('cst_1', $this->adapter->lastActivateSubscriptionCustomerId);
        self::assertNotNull($this->adapter->lastActivateSubscriptionCommand);
        self::assertSame('https://gomrok.example/api/v1/webhooks/mollie/whk_1', $this->adapter->lastActivateSubscriptionCommand->webhookUrl);

        $references = $this->gatewayReferences->forSubscription($subscriptionId);
        self::assertNotEmpty($references);
        self::assertSame(GatewayReferenceType::Subscription, $references[0]->referenceType);
    }

    #[Test]
    public function skipsASubscriptionWithNoKnownMollieCustomerId(): void
    {
        $this->seedSubscription();

        $job = Job::schedule('mollie_subscription_activation_scan', null, $this->clock->now(), $this->clock->now());
        $result = $this->handler->handle($job);

        self::assertSame(['scanned' => 1, 'activated' => 0, 'skipped' => 1], $result->summary);
        self::assertNull($this->adapter->lastActivateSubscriptionCustomerId);
    }
}
