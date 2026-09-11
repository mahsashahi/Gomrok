<?php

declare(strict_types=1);

/**
 * Manually transition a checkout attempt's status (the escape hatch for the
 * exit statuses and every transition not yet driven by a dedicated command).
 *
 *   php bin/SetCheckoutAttemptStatus.php --client=televika --attempt=42 --status=canceled
 *   php bin/SetCheckoutAttemptStatus.php --client=televika --attempt=42 --status=failed --error-code=provider_declined --error-message="Card declined"
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Application\ChangeCheckoutAttemptStatus\ChangeCheckoutAttemptStatusHandler;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'attempt:', 'status:', 'error-code::', 'error-message::']);
if ($opts === false || !isset($opts['client'], $opts['attempt'], $opts['status'])) {
    fwrite(STDERR, "usage: php bin/SetCheckoutAttemptStatus.php --client=<slug|id> --attempt=<ref|id> --status=<status> [--error-code=] [--error-message=]\n");
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

$handler = $container->get(ChangeCheckoutAttemptStatusHandler::class);
assert($handler instanceof ChangeCheckoutAttemptStatusHandler);
$result = $handler->handle($attemptId, $client->id, $asString($opts['status']), $strOrNull('error-code'), $strOrNull('error-message'));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "checkout attempt #{$attemptId} -> {$asString($opts['status'])}\n");
exit(0);
