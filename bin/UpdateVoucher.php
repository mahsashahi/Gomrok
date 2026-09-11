<?php

declare(strict_types=1);

/**
 * Update a voucher's descriptive fields, window, and default discount.
 *
 *   php bin/UpdateVoucher.php --client=televika --voucher=42 --name="Welcome 15%" \
 *       --default-type=percentage --default-percent-bp=1500
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', [
    'client:', 'voucher:', 'name:', 'description::', 'valid-from::', 'valid-until::',
    'first-purchase-only', 'min-purchase-minor::', 'min-purchase-currency::',
    'default-type::', 'default-percent-bp::',
]);
if ($opts === false || !isset($opts['client'], $opts['voucher'], $opts['name']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])) {
    fwrite(STDERR, "usage: php bin/UpdateVoucher.php --client=<slug|id> --voucher=<id> --name=<name> [options]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;
$intOrNull = static fn (string $k): ?int => is_string($opts[$k] ?? null) && ctype_digit($opts[$k]) ? (int) $opts[$k] : null;

$container = ContainerFactory::create();
$directory = $container->get(ClientDirectory::class);
assert($directory instanceof ClientDirectory);
$clientRef = $asString($opts['client']);
$client = ctype_digit($clientRef) ? $directory->findById((int) $clientRef) : $directory->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$handler = $container->get(UpdateVoucherHandler::class);
assert($handler instanceof UpdateVoucherHandler);
$result = $handler->handle(new UpdateVoucherCommand(
    clientId: $client->id,
    voucherId: (int) $opts['voucher'],
    name: $asString($opts['name']),
    description: $strOrNull('description'),
    validFrom: $strOrNull('valid-from'),
    validUntil: $strOrNull('valid-until'),
    firstPurchaseOnly: array_key_exists('first-purchase-only', $opts),
    minPurchaseMinor: $intOrNull('min-purchase-minor'),
    minPurchaseCurrency: $strOrNull('min-purchase-currency'),
    defaultDiscountType: $strOrNull('default-type') ?? 'none',
    defaultPercentBp: $intOrNull('default-percent-bp'),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "updated voucher #{$opts['voucher']}\n");
exit(0);
