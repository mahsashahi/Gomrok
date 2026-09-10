<?php

declare(strict_types=1);

/**
 * List a client's packages with their availability counts.
 *
 *   php bin/ListPackages.php --client=televika
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:']);
if ($opts === false || !isset($opts['client'])) {
    fwrite(STDERR, "usage: php bin/ListPackages.php --client=<slug|id>\n");
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
$rows = $packages->forClient($client->id);

if ($rows === []) {
    fwrite(STDOUT, "no packages for client '{$client->slug}'\n");
    exit(0);
}

$describe = static function (string $label, array $values): string {
    $parts = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_values($values));

    return $parts === [] ? "{$label}=all" : "{$label}=" . implode('/', $parts);
};

foreach ($rows as $package) {
    fwrite(STDOUT, sprintf(
        "%-20s %-8s %-10s  %s  %s  %s  %s\n",
        $package->code,
        $package->status,
        '#' . $package->id,
        $describe('countries', $package->countries),
        $describe('currencies', $package->currencies),
        $describe('methods', $package->methods),
        $describe('accounts', $package->providerAccountIds),
    ));
}

exit(0);
