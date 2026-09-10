<?php

declare(strict_types=1);

/**
 * Create an A/B price list inside a pricing group.
 *
 *   php bin/CreatePriceList.php --client=televika --group=dach --name="List B · -10%" --factor=0.9000
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListCommand;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListHandler;
use Gomrok\Modules\Pricing\Application\CreatePriceList\CreatePriceListResult;
use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'group:', 'name:', 'factor::']);
if ($opts === false || !isset($opts['client'], $opts['group'], $opts['name'])) {
    fwrite(STDERR, "usage: php bin/CreatePriceList.php --client=<slug|id> --group=<slug> --name=<name> [--factor=1.0000]\n");
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

$groups = $container->get(PricingGroupDirectory::class);
assert($groups instanceof PricingGroupDirectory);
$group = $groups->find($client->id, $asString($opts['group']));
if ($group === null) {
    fwrite(STDERR, "error: pricing group '{$asString($opts['group'])}' not found for this client\n");
    exit(1);
}

$handler = $container->get(CreatePriceListHandler::class);
assert($handler instanceof CreatePriceListHandler);
$result = $handler->handle(new CreatePriceListCommand(
    clientId: $client->id,
    pricingGroupId: $group->id,
    name: $asString($opts['name']),
    factor: is_string($opts['factor'] ?? null) && $opts['factor'] !== '' ? $opts['factor'] : '1.0000',
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreatePriceListResult);
fwrite(STDOUT, "created price list #{$payload->priceListId}\n");
exit(0);
