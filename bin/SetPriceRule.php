<?php

declare(strict_types=1);

/**
 * Upsert a price rule for a package. Any dimension left off is a wildcard.
 *
 *   php bin/SetPriceRule.php --client=televika --package=pro \
 *       [--group=dach] [--country=DE] [--provider-account=stripe-live] [--method=card] \
 *       [--purchase-type=subscription] [--interval=yearly] [--currency=EUR] \
 *       (--amount-minor=2700 --currency=EUR  |  --unavailable)
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PricingGroupDirectory;
use Gomrok\Modules\Pricing\Application\SetPriceRule\SetPriceRuleCommand;
use Gomrok\Modules\Pricing\Application\SetPriceRule\SetPriceRuleHandler;
use Gomrok\Modules\Pricing\Application\SetPriceRule\SetPriceRuleResult;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:', 'group::', 'country::', 'provider-account::', 'method::', 'purchase-type::', 'interval::', 'currency::', 'amount-minor::', 'unavailable']);
if ($opts === false || !isset($opts['client'], $opts['package'])) {
    fwrite(STDERR, "usage: php bin/SetPriceRule.php --client=<slug|id> --package=<code> [dimensions...] (--amount-minor=<int> --currency=<ISO> | --unavailable)\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$asIntOrNull = static fn (mixed $v): ?int => is_string($v) && ctype_digit($v) ? (int) $v : null;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;

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

$groupId = null;
if (($g = $strOrNull('group')) !== null) {
    $groups = $container->get(PricingGroupDirectory::class);
    assert($groups instanceof PricingGroupDirectory);
    $group = $groups->find($client->id, $g);
    if ($group === null) {
        fwrite(STDERR, "error: pricing group '{$g}' not found for this client\n");
        exit(1);
    }
    $groupId = $group->id;
}

$providerAccountId = null;
if (($pa = $strOrNull('provider-account')) !== null) {
    $accounts = $container->get(ProviderAccountDirectory::class);
    assert($accounts instanceof ProviderAccountDirectory);
    $summary = $accounts->find($client->id, $pa);
    if ($summary === null) {
        fwrite(STDERR, "error: provider account '{$pa}' not found for this client\n");
        exit(1);
    }
    $providerAccountId = $summary->id;
}

$handler = $container->get(SetPriceRuleHandler::class);
assert($handler instanceof SetPriceRuleHandler);
$result = $handler->handle(new SetPriceRuleCommand(
    clientId: $client->id,
    packageId: $package->id,
    pricingGroupId: $groupId,
    countryCode: $strOrNull('country'),
    providerAccountId: $providerAccountId,
    paymentMethod: $strOrNull('method'),
    purchaseType: $strOrNull('purchase-type'),
    subscriptionInterval: $strOrNull('interval'),
    currencyCode: $strOrNull('currency'),
    isAvailable: !array_key_exists('unavailable', $opts),
    amountMinor: $asIntOrNull($opts['amount-minor'] ?? null),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof SetPriceRuleResult);
fwrite(STDOUT, ($payload->created ? 'created' : 'updated') . " price rule #{$payload->ruleId}\n");
exit(0);
