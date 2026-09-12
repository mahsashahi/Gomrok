<?php

declare(strict_types=1);

/**
 * Look up a Stripe Checkout Session's status.
 *
 *   php bin/StripeGetPaymentStatus.php --client=televika --account=stripe-live --reference=cs_test_...
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'account:', 'reference:']);
if ($opts === false || !isset($opts['client'], $opts['account'], $opts['reference'])) {
    fwrite(STDERR, "usage: php bin/StripeGetPaymentStatus.php --client=<slug|id> --account=<account-slug> --reference=<checkout-session-id>\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;

$container = ContainerFactory::create();

$clients = $container->get(ClientDirectory::class);
assert($clients instanceof ClientDirectory);
$clientRef = $asString($opts['client']);
$client = ctype_digit($clientRef) ? $clients->findById((int) $clientRef) : $clients->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$accounts = $container->get(ProviderAccountDirectory::class);
assert($accounts instanceof ProviderAccountDirectory);
$account = $accounts->find($client->id, $asString($opts['account']));
if ($account === null) {
    fwrite(STDERR, "error: provider account '{$asString($opts['account'])}' not found for this client\n");
    exit(1);
}

$factory = $container->get(ProviderAdapterFactory::class);
assert($factory instanceof ProviderAdapterFactory);

try {
    $status = $factory->for($account->id)->getPaymentStatus($asString($opts['reference']));
} catch (ProviderAdapterException $e) {
    fwrite(STDERR, 'error [' . $e::class . ']: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "{$status->providerReference}: raw={$status->rawStatus} mapped={$status->mappedStatus->value}\n");
exit(0);
