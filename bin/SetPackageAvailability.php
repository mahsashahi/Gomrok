<?php

declare(strict_types=1);

/**
 * Set a package's market availability (full replace per dimension). An empty
 * dimension means "available everywhere for that dimension".
 *
 *   php bin/SetPackageAvailability.php --client=televika --package=pro \
 *       --country=DE --country=NL --currency=EUR [--method=card] [--provider-account=stripe-live]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\SetPackageAvailability\SetPackageAvailabilityCommand;
use Gomrok\Modules\Packages\Application\SetPackageAvailability\SetPackageAvailabilityHandler;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:', 'country::', 'currency::', 'method::', 'provider-account::']);
if ($opts === false || !isset($opts['client'], $opts['package'])) {
    fwrite(STDERR, "usage: php bin/SetPackageAvailability.php --client=<slug|id> --package=<code> [--country=DE ...] [--currency=EUR ...] [--method=card ...] [--provider-account=<slug> ...]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$asList = static function (mixed $v): array {
    if (is_array($v)) {
        return array_values(array_filter($v, static fn ($x): bool => is_string($x) && $x !== ''));
    }

    return is_string($v) && $v !== '' ? [$v] : [];
};

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

$accountsDirectory = $container->get(ProviderAccountDirectory::class);
assert($accountsDirectory instanceof ProviderAccountDirectory);
$accountIds = [];
foreach ($asList($opts['provider-account'] ?? null) as $slug) {
    $summary = $accountsDirectory->find($client->id, $slug);
    if ($summary === null) {
        fwrite(STDERR, "error: provider account '{$slug}' not found for this client\n");
        exit(1);
    }
    $accountIds[] = $summary->id;
}

$handler = $container->get(SetPackageAvailabilityHandler::class);
assert($handler instanceof SetPackageAvailabilityHandler);

$result = $handler->handle(new SetPackageAvailabilityCommand(
    packageId: $package->id,
    countries: $asList($opts['country'] ?? null),
    currencies: $asList($opts['currency'] ?? null),
    methods: $asList($opts['method'] ?? null),
    providerAccountIds: $accountIds,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "package '{$asString($opts['package'])}' availability updated\n");
exit(0);
