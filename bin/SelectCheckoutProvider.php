<?php

declare(strict_types=1);

/**
 * Route a checkout attempt to a provider account and freeze the routing
 * decision snapshot.
 *
 *   php bin/SelectCheckoutProvider.php --client=televika --attempt=42 --mode=test
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderCommand;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderHandler;
use Gomrok\Modules\Checkout\Application\SelectCheckoutProvider\SelectCheckoutProviderResult;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'attempt:', 'mode:', 'device::']);
if ($opts === false || !isset($opts['client'], $opts['attempt'], $opts['mode'])) {
    fwrite(STDERR, "usage: php bin/SelectCheckoutProvider.php --client=<slug|id> --attempt=<ref|id> --mode=live|test [--device=]\n");
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

$attemptRef = $asString($opts['attempt']);
if (ctype_digit($attemptRef)) {
    $attemptId = (int) $attemptRef;
} else {
    $attempts = $container->get(CheckoutAttemptRepository::class);
    assert($attempts instanceof CheckoutAttemptRepository);
    $attempt = $attempts->findByAttemptReference($client->id, $attemptRef);
    if ($attempt === null) {
        fwrite(STDERR, "error: checkout attempt '{$attemptRef}' not found for this client\n");
        exit(1);
    }
    $attemptId = $attempt->id();
}
assert($attemptId !== null);

$handler = $container->get(SelectCheckoutProviderHandler::class);
assert($handler instanceof SelectCheckoutProviderHandler);
$result = $handler->handle(new SelectCheckoutProviderCommand(
    checkoutAttemptId: $attemptId,
    clientId: $client->id,
    mode: $asString($opts['mode']),
    deviceType: $strOrNull('device'),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof SelectCheckoutProviderResult);
fwrite(STDOUT, "selected provider account #{$payload->providerAccountId} (method={$payload->paymentMethod}, purchase_type={$payload->purchaseType})\n");
exit(0);
