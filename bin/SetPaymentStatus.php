<?php

declare(strict_types=1);

/**
 * Manually change a payment's status (the escape hatch for anything not
 * driven by RecordProviderTransaction.php).
 *
 *   php bin/SetPaymentStatus.php --client=televika --payment=1 --status=canceled
 *   php bin/SetPaymentStatus.php --client=televika --payment=1 --status=failed --error-code=provider_declined --error-message="Card declined"
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Payments\Application\ChangePaymentStatus\ChangePaymentStatusHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'payment:', 'status:', 'error-code::', 'error-message::']);
if (
    $opts === false
    || !isset($opts['client'], $opts['payment'], $opts['status'])
    || !is_string($opts['payment']) || !ctype_digit($opts['payment'])
) {
    fwrite(STDERR, "usage: php bin/SetPaymentStatus.php --client=<slug|id> --payment=<id> --status=<status> [--error-code=] [--error-message=]\n");
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

$handler = $container->get(ChangePaymentStatusHandler::class);
assert($handler instanceof ChangePaymentStatusHandler);
$result = $handler->handle((int) $opts['payment'], $client->id, $asString($opts['status']), $strOrNull('error-code'), $strOrNull('error-message'));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "payment #{$asString($opts['payment'])} -> {$asString($opts['status'])}\n");
exit(0);
