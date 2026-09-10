<?php

declare(strict_types=1);

/**
 * Pin an exact price for one package on one non-control A/B list.
 *
 *   php bin/SetPriceListPackagePrice.php --client=televika --list=42 --package=pro --amount-minor=2200 --currency=EUR
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice\SetPriceListPackagePriceCommand;
use Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice\SetPriceListPackagePriceHandler;
use Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice\SetPriceListPackagePriceResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'list:', 'package:', 'amount-minor:', 'currency:']);
if (
    $opts === false
    || !isset($opts['client'], $opts['list'], $opts['package'], $opts['amount-minor'], $opts['currency'])
    || !is_string($opts['list']) || !ctype_digit($opts['list'])
    || !is_string($opts['amount-minor']) || !ctype_digit($opts['amount-minor'])
) {
    fwrite(STDERR, "usage: php bin/SetPriceListPackagePrice.php --client=<slug|id> --list=<id> --package=<code> --amount-minor=<int> --currency=<ISO>\n");
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

$packages = $container->get(PackageDirectory::class);
assert($packages instanceof PackageDirectory);
$package = $packages->find($client->id, $asString($opts['package']));
if ($package === null) {
    fwrite(STDERR, "error: package '{$asString($opts['package'])}' not found for this client\n");
    exit(1);
}

$handler = $container->get(SetPriceListPackagePriceHandler::class);
assert($handler instanceof SetPriceListPackagePriceHandler);
$result = $handler->handle(new SetPriceListPackagePriceCommand(
    clientId: $client->id,
    priceListId: (int) $opts['list'],
    packageId: $package->id,
    amountMinor: (int) $opts['amount-minor'],
    currencyCode: $asString($opts['currency']),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof SetPriceListPackagePriceResult);
fwrite(STDOUT, ($payload->created ? 'created' : 'updated') . " list price for '{$asString($opts['package'])}' on list #{$opts['list']}\n");
exit(0);
