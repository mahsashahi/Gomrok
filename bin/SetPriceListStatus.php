<?php

declare(strict_types=1);

/**
 * Enable or disable an A/B price list. The control list cannot be disabled.
 *
 *   php bin/SetPriceListStatus.php --client=televika --list=42 --disable
 *   php bin/SetPriceListStatus.php --client=televika --list=42 --enable
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\ChangePriceListStatus\ChangePriceListStatusHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'list:', 'enable', 'disable']);
$enable = array_key_exists('enable', $opts);
$disable = array_key_exists('disable', $opts);
if ($opts === false || !isset($opts['client'], $opts['list']) || !is_string($opts['list']) || !ctype_digit($opts['list']) || $enable === $disable) {
    fwrite(STDERR, "usage: php bin/SetPriceListStatus.php --client=<slug|id> --list=<id> (--enable | --disable)\n");
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

$handler = $container->get(ChangePriceListStatusHandler::class);
assert($handler instanceof ChangePriceListStatusHandler);
$listId = (int) $opts['list'];
$result = $enable
    ? $handler->enable($listId, $client->id)
    : $handler->disable($listId, $client->id);

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, 'price list #' . $listId . ($enable ? ' enabled' : ' disabled') . "\n");
exit(0);
