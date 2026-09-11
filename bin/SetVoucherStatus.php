<?php

declare(strict_types=1);

/**
 * Enable or disable a voucher.
 *
 *   php bin/SetVoucherStatus.php --client=televika --voucher=42 --disable
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus\ChangeVoucherStatusHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'voucher:', 'enable', 'disable']);
$enable = array_key_exists('enable', $opts);
$disable = array_key_exists('disable', $opts);
if ($opts === false || !isset($opts['client'], $opts['voucher']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher']) || $enable === $disable) {
    fwrite(STDERR, "usage: php bin/SetVoucherStatus.php --client=<slug|id> --voucher=<id> (--enable | --disable)\n");
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

$handler = $container->get(ChangeVoucherStatusHandler::class);
assert($handler instanceof ChangeVoucherStatusHandler);
$voucherId = (int) $opts['voucher'];
$result = $enable ? $handler->enable($voucherId, $client->id) : $handler->disable($voucherId, $client->id);

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, 'voucher #' . $voucherId . ($enable ? ' enabled' : ' disabled') . "\n");
exit(0);
