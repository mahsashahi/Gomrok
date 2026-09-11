<?php

declare(strict_types=1);

/**
 * Finalise a reservation after a successful payment.
 *
 *   php bin/ConfirmVoucherRedemption.php --client=televika --voucher=1 --attempt=order-42
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\ConfirmVoucherRedemption\ConfirmVoucherRedemptionHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'voucher:', 'attempt:']);
if ($opts === false || !isset($opts['client'], $opts['voucher'], $opts['attempt']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])) {
    fwrite(STDERR, "usage: php bin/ConfirmVoucherRedemption.php --client=<slug|id> --voucher=<id> --attempt=<ref>\n");
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

$handler = $container->get(ConfirmVoucherRedemptionHandler::class);
assert($handler instanceof ConfirmVoucherRedemptionHandler);
$result = $handler->handle((int) $opts['voucher'], $asString($opts['attempt']), $client->id);

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "confirmed redemption for attempt '{$asString($opts['attempt'])}'\n");
exit(0);
