<?php

declare(strict_types=1);

/**
 * Upsert a per-currency discount override for a voucher.
 *
 *   php bin/SetVoucherCurrencyDiscount.php --client=televika --voucher=42 --currency=TRY \
 *       --type=fixed --amount-minor=5000
 *   php bin/SetVoucherCurrencyDiscount.php --client=televika --voucher=42 --currency=GBP \
 *       --type=percentage --percent-bp=1000 --max-discount-minor=800
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountHandler;
use Gomrok\Modules\Vouchers\Application\SetVoucherCurrencyDiscount\SetVoucherCurrencyDiscountResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'voucher:', 'currency:', 'type:', 'percent-bp::', 'amount-minor::', 'max-discount-minor::']);
if ($opts === false || !isset($opts['client'], $opts['voucher'], $opts['currency'], $opts['type']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])) {
    fwrite(STDERR, "usage: php bin/SetVoucherCurrencyDiscount.php --client=<slug|id> --voucher=<id> --currency=<ISO> --type=fixed|percentage|full [--percent-bp=] [--amount-minor=] [--max-discount-minor=]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
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

$handler = $container->get(SetVoucherCurrencyDiscountHandler::class);
assert($handler instanceof SetVoucherCurrencyDiscountHandler);
$result = $handler->handle(new SetVoucherCurrencyDiscountCommand(
    clientId: $client->id,
    voucherId: (int) $opts['voucher'],
    currencyCode: $asString($opts['currency']),
    discountType: $asString($opts['type']),
    percentBp: $intOrNull('percent-bp'),
    amountMinor: $intOrNull('amount-minor'),
    maxDiscountMinor: $intOrNull('max-discount-minor'),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof SetVoucherCurrencyDiscountResult);
fwrite(STDOUT, ($payload->created ? 'created' : 'updated') . " currency discount for voucher #{$opts['voucher']}\n");
exit(0);
