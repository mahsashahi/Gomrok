<?php

declare(strict_types=1);

/**
 * List a client's pricing groups and default package prices.
 *
 *   php bin/ListPricing.php --client=televika
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:']);
if ($opts === false || !isset($opts['client'])) {
    fwrite(STDERR, "usage: php bin/ListPricing.php --client=<slug|id>\n");
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
$rows = $groups->forClient($client->id);

if ($rows === []) {
    fwrite(STDOUT, "no pricing groups for client '{$client->slug}'\n");
    exit(0);
}

foreach ($rows as $group) {
    $countries = $group->countries === [] ? ($group->isDefault ? '(fallback)' : '(none)') : implode('/', $group->countries);
    fwrite(STDOUT, sprintf(
        "%-16s p%-3d %-3s %-8s %s%s\n",
        $group->slug,
        $group->priority,
        $group->currency,
        $group->status,
        $countries,
        $group->deviceType !== null ? "  [{$group->deviceType}]" : '',
    ));
}

exit(0);
