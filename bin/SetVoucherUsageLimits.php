<?php

declare(strict_types=1);

/**
 * Set a voucher's usage caps. Omit a flag to make that dimension unlimited.
 *
 *   php bin/SetVoucherUsageLimits.php --client=televika --voucher=42 --max-per-user=1
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits\SetVoucherUsageLimitsCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherUsageLimits\SetVoucherUsageLimitsHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'voucher:', 'max-total::', 'max-per-user::', 'max-per-client::']);
if ($opts === false || !isset($opts['client'], $opts['voucher']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])) {
    fwrite(STDERR, "usage: php bin/SetVoucherUsageLimits.php --client=<slug|id> --voucher=<id> [--max-total=] [--max-per-user=] [--max-per-client=]\n");
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

$handler = $container->get(SetVoucherUsageLimitsHandler::class);
assert($handler instanceof SetVoucherUsageLimitsHandler);
$result = $handler->handle(new SetVoucherUsageLimitsCommand(
    clientId: $client->id,
    voucherId: (int) $opts['voucher'],
    maxTotalRedemptions: $intOrNull('max-total'),
    maxPerUser: $intOrNull('max-per-user'),
    maxPerClient: $intOrNull('max-per-client'),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "set usage limits for voucher #{$opts['voucher']}\n");
exit(0);
