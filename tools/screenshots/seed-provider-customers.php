<?php

declare(strict_types=1);

/**
 * One-off local seed script for the Phase 27 Customers-screen screenshot —
 * links a couple of demo users to a provider customer identity through the
 * real domain aggregate. Dev-only; not part of the app.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Payments\Domain\ProviderCustomer;
use Gomrok\Modules\Payments\Domain\ProviderCustomerRepository;

$container = ContainerFactory::create();
$providerCustomers = $container->get(ProviderCustomerRepository::class);

$now = new DateTimeImmutable();
$clientId = 1;
$providerAccountId = 1; // stripe-test, seeded by ProviderAccountsSeeder

foreach (['demo-user-1' => 'cus_abc123', 'demo-user-2' => 'cus_def456'] as $user => $providerCustomerId) {
    $existing = $providerCustomers->find($providerAccountId, $user);
    if ($existing !== null) {
        echo "  {$user} already linked\n";
        continue;
    }
    $link = ProviderCustomer::link($clientId, $providerAccountId, $user, $providerCustomerId, $now);
    $providerCustomers->save($link);
    echo "  linked {$user} -> {$providerCustomerId}\n";
}

echo "Done.\n";
