<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Customers;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Customers\CustomersListHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Payments\Domain\ProviderCustomer;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\InMemoryPaymentDirectory;
use Gomrok\Tests\Support\InMemoryPaymentRepository;
use Gomrok\Tests\Support\InMemoryProviderCustomerRepository;
use Gomrok\Tests\Support\InMemorySubscriptionDirectory;
use Gomrok\Tests\Support\InMemorySubscriptionRepository;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CustomersListHandlerTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;
    private const PROVIDER_ACCOUNT = 1;

    private DateTimeImmutable $now;
    private InMemoryClientDirectory $clients;
    private InMemoryPaymentRepository $paymentsRepo;
    private InMemorySubscriptionRepository $subscriptionsRepo;
    private InMemoryProviderCustomerRepository $providerCustomersRepo;
    private CustomersListHandler $handler;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $this->clients = new InMemoryClientDirectory();
        $this->clients->add(new ClientSnapshot(self::CLIENT, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));

        $this->paymentsRepo = new InMemoryPaymentRepository();
        $this->subscriptionsRepo = new InMemorySubscriptionRepository();
        $this->providerCustomersRepo = new InMemoryProviderCustomerRepository();

        $this->handler = new CustomersListHandler(
            $this->clients,
            new InMemoryPaymentDirectory($this->paymentsRepo),
            new InMemorySubscriptionDirectory($this->subscriptionsRepo),
            $this->providerCustomersRepo,
            (new StubProviderAccountDirectory())->add(self::PROVIDER_ACCOUNT, self::CLIENT, 'stripe-test', 'stripe'),
            (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro package'),
        );
    }

    #[Test]
    public function oneRowPerDistinctClientUserRefAcrossPaymentsAndSubscriptions(): void
    {
        $this->seedPayment('user-1', PaymentStatus::Paid);
        $this->seedPayment('user-1', PaymentStatus::Paid);
        $this->seedPayment('user-2', PaymentStatus::Failed);
        $this->seedSubscription('user-3');

        $result = $this->handler->forClient(self::CLIENT, null);

        $refs = array_map(static fn ($r) => $r->clientUserRef, $result->rows);
        sort($refs);
        self::assertSame(['user-1', 'user-2', 'user-3'], $refs);
    }

    #[Test]
    public function lifetimeSpendSumsOnlyPaidPaymentsInTheClientsCurrency(): void
    {
        $this->seedPayment('user-1', PaymentStatus::Paid, 2900);
        $this->seedPayment('user-1', PaymentStatus::Paid, 1000);
        $this->seedPayment('user-1', PaymentStatus::Failed, 5000);

        $result = $this->handler->forClient(self::CLIENT, null);

        self::assertSame('€39.00', $result->rows[0]->lifetimeSpend);
    }

    #[Test]
    public function statusIsActiveOnlyWhenThereIsAnActiveOrTrialingSubscription(): void
    {
        $this->seedSubscription('user-1');
        $active = $this->subscriptionsRepo->findById(1);
        self::assertNotNull($active);

        $this->seedPayment('user-2', PaymentStatus::Paid);

        $result = $this->handler->forClient(self::CLIENT, null);

        $byRef = [];
        foreach ($result->rows as $row) {
            $byRef[$row->clientUserRef] = $row->status;
        }
        self::assertSame('Active', $byRef['user-1']);
        self::assertSame('Customer', $byRef['user-2']);
    }

    #[Test]
    public function providerReferencesAreResolvedWithTheirProviderAccountName(): void
    {
        $this->seedPayment('user-1', PaymentStatus::Paid);
        $this->providerCustomersRepo->save(ProviderCustomer::link(self::CLIENT, self::PROVIDER_ACCOUNT, 'user-1', 'cus_abc123', $this->now));

        $result = $this->handler->forClient(self::CLIENT, null);

        self::assertCount(1, $result->rows[0]->providerRefs);
        self::assertSame('cus_abc123', $result->rows[0]->providerRefs[0]->providerCustomerId);
        self::assertNotSame('—', $result->rows[0]->providerRefs[0]->providerName);
    }

    #[Test]
    public function searchFiltersByClientUserRefSubstring(): void
    {
        $this->seedPayment('alice-1', PaymentStatus::Paid);
        $this->seedPayment('bob-2', PaymentStatus::Paid);

        $result = $this->handler->forClient(self::CLIENT, 'alice');

        self::assertCount(1, $result->rows);
        self::assertSame('alice-1', $result->rows[0]->clientUserRef);
    }

    private function seedPayment(string $ref, PaymentStatus $status, int $amountMinor = 2900): void
    {
        $payment = Payment::create(self::CLIENT, null, $ref, self::PACKAGE, 'DE', 'EUR', $amountMinor, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $this->now);
        if ($status !== PaymentStatus::Created) {
            $payment->transitionTo(PaymentStatus::Pending, $this->now);
        }
        if ($status !== PaymentStatus::Created && $status !== PaymentStatus::Pending) {
            $payment->transitionTo($status, $this->now);
        }
        $this->paymentsRepo->save($payment);
    }

    private function seedSubscription(string $ref): void
    {
        $subscription = Subscription::create(self::CLIENT, $ref, 100, self::PACKAGE, self::PROVIDER_ACCOUNT, 'EUR', 2900, null, SubscriptionInterval::Monthly, false, null, $this->now);
        $this->subscriptionsRepo->save($subscription);
        self::assertSame(SubscriptionStatus::Active, $subscription->status());
    }
}
