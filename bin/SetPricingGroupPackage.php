<?php

declare(strict_types=1);

/**
 * Set how a package is priced / displayed in a pricing group.
 *
 *   php bin/SetPricingGroupPackage.php --client=televika --group=dach --package=pro \
 *       --status=override --amount-minor=2400 --currency=EUR [--name="Pro (DACH)"] \
 *       [--badge="Popular"] [--highlight|--unhighlight] [--order=1]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageCommand;
use Gomrok\Modules\Pricing\Application\SetPricingGroupPackage\SetPricingGroupPackageHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'group:', 'package:', 'status::', 'amount-minor::', 'currency::', 'name::', 'badge::', 'highlight', 'unhighlight', 'order::']);
if ($opts === false || !isset($opts['client'], $opts['group'], $opts['package'])) {
    fwrite(STDERR, "usage: php bin/SetPricingGroupPackage.php --client=<slug|id> --group=<slug> --package=<code> [--status=default|override|disabled] [--amount-minor=<int> --currency=<ISO>] [--name=<n>] [--badge=<b>] [--highlight|--unhighlight] [--order=N]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$asIntOrNull = static fn (mixed $v): ?int => is_string($v) && ctype_digit($v) ? (int) $v : null;

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

$packages = $container->get(PackageDirectory::class);
assert($packages instanceof PackageDirectory);
$package = $packages->find($client->id, $asString($opts['package']));
if ($package === null) {
    fwrite(STDERR, "error: package '{$asString($opts['package'])}' not found for this client\n");
    exit(1);
}

$highlighted = null;
if (array_key_exists('highlight', $opts)) {
    $highlighted = true;
} elseif (array_key_exists('unhighlight', $opts)) {
    $highlighted = false;
}

$handler = $container->get(SetPricingGroupPackageHandler::class);
assert($handler instanceof SetPricingGroupPackageHandler);
$result = $handler->handle(new SetPricingGroupPackageCommand(
    pricingGroupId: $group->id,
    packageId: $package->id,
    status: ($s = $asString($opts['status'] ?? null)) !== '' ? $s : 'default',
    amountMinor: $asIntOrNull($opts['amount-minor'] ?? null),
    currency: ($c = $asString($opts['currency'] ?? null)) !== '' ? $c : null,
    nameOverride: ($n = $asString($opts['name'] ?? null)) !== '' ? $n : null,
    badgeOverride: ($b = $asString($opts['badge'] ?? null)) !== '' ? $b : null,
    highlightedOverride: $highlighted,
    displayOrder: $asIntOrNull($opts['order'] ?? null) ?? 0,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "pricing for '{$asString($opts['package'])}' in group '{$asString($opts['group'])}' updated\n");
exit(0);
