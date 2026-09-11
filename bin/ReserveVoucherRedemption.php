<?php

declare(strict_types=1);

/**
 * Reserve a voucher's usage against a checkout attempt (re-checks eligibility,
 * computes the discount, and locks in the caps). Idempotent by attempt.
 *
 *   php bin/ReserveVoucherRedemption.php --client=televika --voucher=1 --attempt=order-42 \
 *       --currency=EUR --price-minor=2900 [--country=DE] [--package=7] [--provider-account=5] \
 *       [--method=card] [--purchase-type=one_time_payment] [--interval=yearly] \
 *       [--client-user=user-123] [--first-purchase]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionCommand;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', [
    'client:', 'voucher:', 'attempt:', 'currency:', 'price-minor:',
    'country::', 'package::', 'provider-account::', 'method::', 'purchase-type::', 'interval::',
    'client-user::', 'first-purchase', 'not-first-purchase',
]);
if (
    $opts === false
    || !isset($opts['client'], $opts['voucher'], $opts['attempt'], $opts['currency'], $opts['price-minor'])
    || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])
    || !is_string($opts['price-minor']) || !ctype_digit($opts['price-minor'])
) {
    fwrite(STDERR, "usage: php bin/ReserveVoucherRedemption.php --client=<slug|id> --voucher=<id> --attempt=<ref> --currency=<ISO> --price-minor=<int> [options]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;
$intOrNull = static fn (string $k): ?int => is_string($opts[$k] ?? null) && ctype_digit($opts[$k]) ? (int) $opts[$k] : null;

$isFirstPurchase = null;
if (array_key_exists('first-purchase', $opts)) {
    $isFirstPurchase = true;
} elseif (array_key_exists('not-first-purchase', $opts)) {
    $isFirstPurchase = false;
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

$handler = $container->get(ReserveVoucherRedemptionHandler::class);
assert($handler instanceof ReserveVoucherRedemptionHandler);
$result = $handler->handle(new ReserveVoucherRedemptionCommand(
    clientId: $client->id,
    voucherId: (int) $opts['voucher'],
    attemptReference: $asString($opts['attempt']),
    currencyCode: $asString($opts['currency']),
    priceMinor: (int) $opts['price-minor'],
    country: $strOrNull('country'),
    packageId: $intOrNull('package'),
    providerAccountId: $intOrNull('provider-account'),
    paymentMethod: $strOrNull('method'),
    purchaseType: $strOrNull('purchase-type'),
    subscriptionInterval: $strOrNull('interval'),
    clientUserRef: $strOrNull('client-user'),
    isFirstPurchase: $isFirstPurchase,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof ReserveVoucherRedemptionResult);
fwrite(STDOUT, sprintf(
    "redemption #%d [%s]: price=%d discount=%d (nominal %d) payable=%d %s\n",
    $payload->redemptionId,
    $payload->status,
    $payload->priceMinor,
    $payload->appliedDiscountMinor,
    $payload->nominalDiscountMinor,
    $payload->payableMinor,
    $payload->currencyCode,
));
exit(0);
