<?php

declare(strict_types=1);

/**
 * List a pricing group's A/B price lists (control first).
 *
 *   php bin/ListPriceLists.php --client=televika --group=dach
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\PriceListDirectory;
use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'group:']);
if ($opts === false || !isset($opts['client'], $opts['group'])) {
    fwrite(STDERR, "usage: php bin/ListPriceLists.php --client=<slug|id> --group=<slug>\n");
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

$lists = $container->get(PriceListDirectory::class);
assert($lists instanceof PriceListDirectory);
$rows = $lists->forGroup($group->id);

if ($rows === []) {
    fwrite(STDOUT, "no price lists for group '{$asString($opts['group'])}'\n");
    exit(0);
}

foreach ($rows as $r) {
    $flags = ($r->isControl ? 'control' : 'experiment') . ($r->isEnabled ? '' : ', disabled');
    fwrite(STDOUT, sprintf("#%-4d %-24s x%-8s [%s]\n", $r->id, $r->name, $r->factor, $flags));
    foreach ($r->packagePrices as $p) {
        fwrite(STDOUT, sprintf("       package#%-4d -> %d %s\n", $p['package_id'], $p['amount_minor'], $p['currency']));
    }
}

exit(0);
