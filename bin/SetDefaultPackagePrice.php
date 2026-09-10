<?php

declare(strict_types=1);

/**
 * Set a package's one baseline price (in minor units).
 *
 *   php bin/SetDefaultPackagePrice.php --client=televika --package=pro --amount-minor=2900 --currency=EUR
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceCommand;
use Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice\SetDefaultPackagePriceHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:', 'amount-minor:', 'currency:']);
if ($opts === false || !isset($opts['client'], $opts['package'], $opts['amount-minor'], $opts['currency'])) {
    fwrite(STDERR, "usage: php bin/SetDefaultPackagePrice.php --client=<slug|id> --package=<code> --amount-minor=<int> --currency=<ISO>\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$amountRaw = $asString($opts['amount-minor']);
if (!ctype_digit($amountRaw)) {
    fwrite(STDERR, "error: --amount-minor must be a non-negative integer\n");
    exit(2);
}

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

$handler = $container->get(SetDefaultPackagePriceHandler::class);
assert($handler instanceof SetDefaultPackagePriceHandler);
$result = $handler->handle(new SetDefaultPackagePriceCommand($package->id, (int) $amountRaw, $asString($opts['currency'])));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "default price for '{$asString($opts['package'])}' set to {$amountRaw} " . strtoupper($asString($opts['currency'])) . " (minor)\n");
exit(0);
