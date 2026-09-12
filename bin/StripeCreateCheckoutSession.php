<?php

declare(strict_types=1);

/**
 * Create a real Stripe Checkout Session for a provider account and print the
 * redirect URL (Phase 21 — standalone adapter demonstration; the payment
 * creation flow that wires this into the Payments module is Phase 24).
 * Requires the account to already hold a real Stripe test/live secret key
 * (`composer provider-account:create --provider-type=stripe ...`).
 *
 *   php bin/StripeCreateCheckoutSession.php --client=televika --account=stripe-live \
 *       --attempt=order-42 --amount-minor=2900 --currency=EUR --description="Pro package" \
 *       --success-url=https://example.com/success --cancel-url=https://example.com/cancel \
 *       [--customer-email=user@example.com]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', [
    'client:', 'account:', 'attempt:', 'amount-minor:', 'currency:', 'description:',
    'success-url:', 'cancel-url:', 'customer-email::',
]);
if (
    $opts === false
    || !isset($opts['client'], $opts['account'], $opts['attempt'], $opts['amount-minor'], $opts['currency'], $opts['description'], $opts['success-url'], $opts['cancel-url'])
    || !is_string($opts['amount-minor']) || !ctype_digit($opts['amount-minor'])
) {
    fwrite(STDERR, "usage: php bin/StripeCreateCheckoutSession.php --client=<slug|id> --account=<account-slug> --attempt=<ref> --amount-minor=<n> --currency=<ISO> --description=<text> --success-url=<url> --cancel-url=<url> [--customer-email=]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;

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
    $adapter = $factory->for($account->id);
    $result = $adapter->createPayment(new CreatePaymentCommand(
        attemptReference: $asString($opts['attempt']),
        amountMinor: (int) $opts['amount-minor'],
        currencyCode: $asString($opts['currency']),
        description: $asString($opts['description']),
        successUrl: $asString($opts['success-url']),
        cancelUrl: $asString($opts['cancel-url']),
        customerEmail: $strOrNull('customer-email'),
    ));
} catch (ProviderAdapterException $e) {
    fwrite(STDERR, 'error [' . $e::class . ']: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "checkout session {$result->providerReference} [{$result->rawStatus}]\n{$result->redirectUrl}\n");
exit(0);
