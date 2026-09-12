<?php

declare(strict_types=1);

/**
 * Create the final payment from a confirmed checkout attempt.
 *
 *   php bin/CreatePayment.php --client=televika --attempt=42
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentCommand;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentHandler;
use Gomrok\Modules\Payments\Application\CreatePayment\CreatePaymentResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'attempt:']);
if ($opts === false || !isset($opts['client'], $opts['attempt'])) {
    fwrite(STDERR, "usage: php bin/CreatePayment.php --client=<slug|id> --attempt=<ref|id>\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;

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

$handler = $container->get(CreatePaymentHandler::class);
assert($handler instanceof CreatePaymentHandler);
$result = $handler->handle(new CreatePaymentCommand(clientId: $client->id, checkoutAttemptId: $attemptId));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreatePaymentResult);
fwrite(STDOUT, "payment #{$payload->paymentId} [{$payload->status}] amount={$payload->amountMinor} {$payload->currencyCode}\n");
exit(0);
