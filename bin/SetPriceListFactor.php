<?php

declare(strict_types=1);

/**
 * Change a non-control price list's multiplier.
 *
 *   php bin/SetPriceListFactor.php --client=televika --list=42 --factor=0.8500
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\SetPriceListFactor\SetPriceListFactorCommand;
use Gomrok\Modules\Pricing\Application\SetPriceListFactor\SetPriceListFactorHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'list:', 'factor:']);
if ($opts === false || !isset($opts['client'], $opts['list'], $opts['factor']) || !is_string($opts['list']) || !ctype_digit($opts['list'])) {
    fwrite(STDERR, "usage: php bin/SetPriceListFactor.php --client=<slug|id> --list=<id> --factor=<decimal>\n");
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

$handler = $container->get(SetPriceListFactorHandler::class);
assert($handler instanceof SetPriceListFactorHandler);
$result = $handler->handle(new SetPriceListFactorCommand(
    clientId: $client->id,
    priceListId: (int) $opts['list'],
    factor: $asString($opts['factor']),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "price list #{$opts['list']} factor set to {$asString($opts['factor'])}\n");
exit(0);
