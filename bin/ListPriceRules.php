<?php

declare(strict_types=1);

/**
 * List the price rules for a package, most specific first.
 *
 *   php bin/ListPriceRules.php --client=televika --package=pro
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PriceRuleDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:']);
if ($opts === false || !isset($opts['client'], $opts['package'])) {
    fwrite(STDERR, "usage: php bin/ListPriceRules.php --client=<slug|id> --package=<code>\n");
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

$rules = $container->get(PriceRuleDirectory::class);
assert($rules instanceof PriceRuleDirectory);
$rows = $rules->forClientPackage($client->id, $package->id);

if ($rows === []) {
    fwrite(STDOUT, "no price rules for '{$asString($opts['package'])}'\n");
    exit(0);
}

usort($rows, static fn ($a, $b): int => $b->specificity <=> $a->specificity);

foreach ($rows as $r) {
    $dims = array_values(array_filter([
        $r->pricingGroupId !== null ? "group#{$r->pricingGroupId}" : null,
        $r->countryCode,
        $r->providerAccountId !== null ? "acct#{$r->providerAccountId}" : null,
        $r->paymentMethod,
        $r->purchaseType,
        $r->subscriptionInterval,
        $r->currencyCode !== null ? "ccy={$r->currencyCode}" : null,
    ], static fn (?string $x): bool => $x !== null && $x !== ''));
    $value = $r->isAvailable ? ((string) ($r->amountMinor ?? '?')) . ' minor' : 'UNAVAILABLE';
    fwrite(STDOUT, sprintf("#%-4d s%d  %-40s -> %s\n", $r->id, $r->specificity, $dims === [] ? '(all wildcards)' : implode(' ', $dims), $value));
}

exit(0);
