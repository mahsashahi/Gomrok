<?php

declare(strict_types=1);

/**
 * Remove a per-currency discount override (the currency reverts to the
 * voucher's default discount).
 *
 *   php bin/RemoveVoucherCurrencyDiscount.php --client=televika --voucher=42 --currency=TRY
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\RemoveVoucherCurrencyDiscount\RemoveVoucherCurrencyDiscountHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'voucher:', 'currency:']);
if ($opts === false || !isset($opts['client'], $opts['voucher'], $opts['currency']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])) {
    fwrite(STDERR, "usage: php bin/RemoveVoucherCurrencyDiscount.php --client=<slug|id> --voucher=<id> --currency=<ISO>\n");
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

$handler = $container->get(RemoveVoucherCurrencyDiscountHandler::class);
assert($handler instanceof RemoveVoucherCurrencyDiscountHandler);
$result = $handler->handle((int) $opts['voucher'], $asString($opts['currency']), $client->id);

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "removed currency discount for voucher #{$opts['voucher']}\n");
exit(0);
