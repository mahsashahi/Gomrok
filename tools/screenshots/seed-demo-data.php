<?php

declare(strict_types=1);

/**
 * One-off local seed script for Phase 27 Home-screen screenshots — creates a
 * handful of realistic Payment/Subscription rows through the real domain
 * aggregates and repositories (not raw SQL), so the rendered dashboard
 * reflects genuinely valid application state. Dev-only; not part of the app.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;

$container = ContainerFactory::create();
$payments = $container->get(PaymentRepository::class);
$subscriptions = $container->get(SubscriptionRepository::class);
$checkoutAttempts = $container->get(CheckoutAttemptRepository::class);

$now = new DateTimeImmutable();
$clientId = 1; // local-dev, seeded by ClientsSeeder
$packageId = 2; // "pro", seeded by PackagesSeeder

function makePayment(PaymentRepository $repo, int $clientId, int $packageId, PaymentStatus $status, DateTimeImmutable $createdAt, string $user): void
{
    $payment = Payment::create($clientId, null, $user, $packageId, 'DE', 'EUR', 2400, PurchaseType::OneTimePayment, PaymentMethod::Card, null, $createdAt);
    if ($status !== PaymentStatus::Created) {
        $payment->transitionTo(PaymentStatus::Pending, $createdAt);
    }
    if ($status !== PaymentStatus::Created && $status !== PaymentStatus::Pending) {
        $payment->transitionTo($status, $createdAt);
    }
    $repo->save($payment);
    echo "  payment {$payment->id()} — {$status->value} — {$createdAt->format('Y-m-d H:i')}\n";
}

echo "Seeding demo payments...\n";
makePayment($payments, $clientId, $packageId, PaymentStatus::Paid, $now, 'demo-user-1');
makePayment($payments, $clientId, $packageId, PaymentStatus::Paid, $now->modify('-1 hour'), 'demo-user-2');
makePayment($payments, $clientId, $packageId, PaymentStatus::Paid, $now->modify('-3 hours'), 'demo-user-3');
makePayment($payments, $clientId, $packageId, PaymentStatus::Failed, $now->modify('-2 hours'), 'demo-user-4');
makePayment($payments, $clientId, $packageId, PaymentStatus::Paid, $now->modify('-1 day'), 'demo-user-5');
makePayment($payments, $clientId, $packageId, PaymentStatus::Paid, $now->modify('-3 days'), 'demo-user-6');
makePayment($payments, $clientId, $packageId, PaymentStatus::Paid, $now->modify('-9 days'), 'demo-user-7');

echo "Seeding demo subscriptions...\n";
foreach (['demo-user-1', 'demo-user-2', 'demo-user-3'] as $i => $user) {
    $createdAt = $now->modify("-{$i} days");

    $attempt = CheckoutAttempt::start($clientId, $user, "demo-sub-order-{$i}", $packageId, 'DE', 'EUR', PurchaseType::Subscription, null, SubscriptionInterval::Monthly, $createdAt);
    $checkoutAttempts->save($attempt);
    $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $createdAt);
    $attempt->transitionTo(CheckoutAttemptStatus::Confirmed, $createdAt);
    $checkoutAttempts->save($attempt);
    $attemptId = $attempt->id();

    $subscription = Subscription::create($clientId, $user, $attemptId, $packageId, 1, 'EUR', 2400, null, SubscriptionInterval::Monthly, false, null, $createdAt);
    $subscriptions->save($subscription);
    echo "  subscription {$subscription->id()} — {$subscription->status()->value}\n";
}

echo "Done.\n";
