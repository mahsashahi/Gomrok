<?php

declare(strict_types=1);

/**
 * Set a package's per-country purchase-type overrides (full replace). Each
 * --override is `COUNTRY:type[,type...]`. An override narrows the global set for
 * that country; omitted countries inherit the global set.
 *
 *   php bin/SetPackageCountryCapabilities.php --client=televika --package=pro \
 *       --override=TR:one_time_payment --override=DE:one_time_payment,subscription
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\SetPackageCountryPurchaseCapabilities\SetPackageCountryPurchaseCapabilitiesCommand;
use Gomrok\Modules\Packages\Application\SetPackageCountryPurchaseCapabilities\SetPackageCountryPurchaseCapabilitiesHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:', 'override::']);
if ($opts === false || !isset($opts['client'], $opts['package'])) {
    fwrite(STDERR, "usage: php bin/SetPackageCountryCapabilities.php --client=<slug|id> --package=<code> [--override=CC:type[,type] ...]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$overrideRaw = [];
if (isset($opts['override'])) {
    $overrideRaw = is_array($opts['override'])
        ? array_values(array_filter($opts['override'], static fn ($x): bool => is_string($x) && $x !== ''))
        : [$asString($opts['override'])];
}

/** @var array<string, list<string>> $overridesByCountry */
$overridesByCountry = [];
foreach ($overrideRaw as $entry) {
    [$country, $types] = array_pad(explode(':', $entry, 2), 2, '');
    $country = strtoupper(trim($country));
    if ($country === '' || $types === '') {
        fwrite(STDERR, "error: malformed --override '{$entry}' (expected CC:type[,type])\n");
        exit(2);
    }
    $overridesByCountry[$country] = array_values(array_filter(
        array_map('trim', explode(',', $types)),
        static fn (string $s): bool => $s !== '',
    ));
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

$handler = $container->get(SetPackageCountryPurchaseCapabilitiesHandler::class);
assert($handler instanceof SetPackageCountryPurchaseCapabilitiesHandler);

$result = $handler->handle(new SetPackageCountryPurchaseCapabilitiesCommand($package->id, $overridesByCountry));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$summary = $overridesByCountry === [] ? '(cleared)' : implode('; ', array_map(
    static fn (string $c, array $t): string => $c . ':' . implode(',', $t),
    array_keys($overridesByCountry),
    array_values($overridesByCountry),
));
fwrite(STDOUT, "package '{$asString($opts['package'])}' country overrides: {$summary}\n");
exit(0);
