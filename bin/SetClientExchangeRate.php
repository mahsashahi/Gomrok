<?php

declare(strict_types=1);

/**
 * Set a client FX rate ("1 base = rate quote").
 *
 *   php bin/SetClientExchangeRate.php --client=televika --base=EUR --quote=USD --rate=1.08 [--from="2026-01-01T00:00:00Z"]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\SetClientExchangeRate\SetClientExchangeRateCommand;
use Gomrok\Modules\Pricing\Application\SetClientExchangeRate\SetClientExchangeRateHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'base:', 'quote:', 'rate:', 'from::']);
if ($opts === false || !isset($opts['client'], $opts['base'], $opts['quote'], $opts['rate'])) {
    fwrite(STDERR, "usage: php bin/SetClientExchangeRate.php --client=<slug|id> --base=<ISO> --quote=<ISO> --rate=<decimal> [--from=<datetime>]\n");
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

$handler = $container->get(SetClientExchangeRateHandler::class);
assert($handler instanceof SetClientExchangeRateHandler);
$result = $handler->handle(new SetClientExchangeRateCommand(
    clientId: $client->id,
    baseCurrency: $asString($opts['base']),
    quoteCurrency: $asString($opts['quote']),
    rate: $asString($opts['rate']),
    effectiveFrom: ($f = $asString($opts['from'] ?? null)) !== '' ? $f : null,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, '1 ' . strtoupper($asString($opts['base'])) . ' = ' . $asString($opts['rate']) . ' ' . strtoupper($asString($opts['quote'])) . "\n");
exit(0);
