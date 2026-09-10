<?php

declare(strict_types=1);

/**
 * Set a package's global purchase-type set (full replace).
 *
 *   php bin/SetPackageCapabilities.php --client=televika --package=pro \
 *       --capability=one_time_payment --capability=subscription \
 *       [--trial-days=14] [--duration-months=1]
 *
 * `--trial-days` is applied to every subscription / recurring_payment capability
 * listed; `--duration-months` to all. Finer per-type config → admin panel.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\PurchaseCapabilityInput;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesCommand;
use Gomrok\Modules\Packages\Application\SetPackagePurchaseCapabilities\SetPackagePurchaseCapabilitiesHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:', 'capability:', 'trial-days::', 'duration-months::']);
if ($opts === false || !isset($opts['client'], $opts['package'], $opts['capability'])) {
    fwrite(STDERR, "usage: php bin/SetPackageCapabilities.php --client=<slug|id> --package=<code> --capability=<type> [--capability=<type> ...] [--trial-days=N] [--duration-months=N]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$asIntOrNull = static fn (mixed $v): ?int => is_string($v) && ctype_digit($v) ? (int) $v : null;
$capabilityValues = is_array($opts['capability'])
    ? array_values(array_filter($opts['capability'], static fn ($x): bool => is_string($x) && $x !== ''))
    : [$asString($opts['capability'])];

$trialDays = $asIntOrNull($opts['trial-days'] ?? null);
$durationMonths = $asIntOrNull($opts['duration-months'] ?? null);

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

$inputs = [];
foreach ($capabilityValues as $type) {
    $wantsTrial = $trialDays !== null && in_array($type, ['subscription', 'recurring_payment'], true);
    $inputs[] = new PurchaseCapabilityInput($type, $wantsTrial, $wantsTrial ? $trialDays : null, $durationMonths);
}

$handler = $container->get(SetPackagePurchaseCapabilitiesHandler::class);
assert($handler instanceof SetPackagePurchaseCapabilitiesHandler);

$result = $handler->handle(new SetPackagePurchaseCapabilitiesCommand($package->id, $inputs));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "package '{$asString($opts['package'])}' capabilities: " . implode(', ', $capabilityValues) . "\n");
exit(0);
