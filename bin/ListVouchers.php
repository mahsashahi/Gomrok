<?php

declare(strict_types=1);

/**
 * List a client's vouchers with their eligibility rules and currency overrides.
 *
 *   php bin/ListVouchers.php --client=televika
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:']);
if ($opts === false || !isset($opts['client'])) {
    fwrite(STDERR, "usage: php bin/ListVouchers.php --client=<slug|id>\n");
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

$vouchers = $container->get(VoucherDirectory::class);
assert($vouchers instanceof VoucherDirectory);
$rows = $vouchers->forClient($client->id);

if ($rows === []) {
    fwrite(STDOUT, "no vouchers for client '{$clientRef}'\n");
    exit(0);
}

foreach ($rows as $v) {
    $discount = $v->defaultDiscountType === 'percentage' ? "{$v->defaultPercentBp}bp" : $v->defaultDiscountType;
    $limits = sprintf('total=%s per-user=%s per-client=%s used=%d', $v->maxTotalRedemptions ?? '∞', $v->maxPerUser ?? '∞', $v->maxPerClient ?? '∞', $v->redeemedCount);
    fwrite(STDOUT, sprintf("#%-4d %-24s [%s] default=%s (%s)\n", $v->id, $v->code, $v->status, $discount, $limits));
    foreach ($v->eligibilityRules as $rule) {
        fwrite(STDOUT, "       rule: {$rule['dimension']}={$rule['value']}\n");
    }
    foreach ($v->currencyDiscounts as $d) {
        $value = $d['discount_type'] === 'percentage' ? "{$d['percent_bp']}bp" : ($d['discount_type'] === 'fixed' ? "{$d['amount_minor']} minor" : 'full');
        fwrite(STDOUT, "       override: {$d['currency']} -> {$d['discount_type']} ({$value})\n");
    }
}

exit(0);
