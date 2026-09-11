<?php

declare(strict_types=1);

/**
 * Start a checkout attempt.
 *
 *   php bin/CreateCheckoutAttempt.php --client=televika --attempt=order-42 --package=7 \
 *       --country=DE --currency=EUR [--client-user=user-1] [--purchase-type=one_time_payment] \
 *       [--method=card] [--interval=yearly]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptCommand;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptHandler;
use Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt\CreateCheckoutAttemptResult;
use Gomrok\Modules\Clients\Application\ClientDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', [
    'client:', 'attempt:', 'package:', 'country:', 'currency:',
    'client-user::', 'purchase-type::', 'method::', 'interval::',
]);
if (
    $opts === false
    || !isset($opts['client'], $opts['attempt'], $opts['package'], $opts['country'], $opts['currency'])
    || !is_string($opts['package']) || !ctype_digit($opts['package'])
) {
    fwrite(STDERR, "usage: php bin/CreateCheckoutAttempt.php --client=<slug|id> --attempt=<ref> --package=<id> --country=<CC> --currency=<ISO> [options]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;

$container = ContainerFactory::create();
$directory = $container->get(ClientDirectory::class);
assert($directory instanceof ClientDirectory);
$clientRef = $asString($opts['client']);
$client = ctype_digit($clientRef) ? $directory->findById((int) $clientRef) : $directory->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$handler = $container->get(CreateCheckoutAttemptHandler::class);
assert($handler instanceof CreateCheckoutAttemptHandler);
$result = $handler->handle(new CreateCheckoutAttemptCommand(
    clientId: $client->id,
    attemptReference: $asString($opts['attempt']),
    packageId: (int) $opts['package'],
    country: $asString($opts['country']),
    currencyCode: $asString($opts['currency']),
    clientUserRef: $strOrNull('client-user'),
    purchaseType: $strOrNull('purchase-type'),
    paymentMethod: $strOrNull('method'),
    subscriptionInterval: $strOrNull('interval'),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreateCheckoutAttemptResult);
fwrite(STDOUT, "checkout attempt #{$payload->checkoutAttemptId} [{$payload->status}]\n");
exit(0);
