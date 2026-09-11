<?php

declare(strict_types=1);

/**
 * Create a voucher with its default discount.
 *
 *   php bin/CreateVoucher.php --client=televika --code=WELCOME10 --name="Welcome 10%" \
 *       --default-type=percentage --default-percent-bp=1000 \
 *       [--description=...] [--valid-from=2026-01-01T00:00:00Z] [--valid-until=...] \
 *       [--first-purchase-only] [--min-purchase-minor=1000 --min-purchase-currency=EUR]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherHandler;
use Gomrok\Modules\Vouchers\Application\CreateVoucher\CreateVoucherResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', [
    'client:', 'code:', 'name:', 'description::', 'valid-from::', 'valid-until::',
    'first-purchase-only', 'min-purchase-minor::', 'min-purchase-currency::',
    'default-type::', 'default-percent-bp::',
]);
if ($opts === false || !isset($opts['client'], $opts['code'], $opts['name'])) {
    fwrite(STDERR, "usage: php bin/CreateVoucher.php --client=<slug|id> --code=<code> --name=<name> [options]\n");
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

$handler = $container->get(CreateVoucherHandler::class);
assert($handler instanceof CreateVoucherHandler);
$result = $handler->handle(new CreateVoucherCommand(
    clientId: $client->id,
    code: $asString($opts['code']),
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

$payload = $result->value();
assert($payload instanceof CreateVoucherResult);
fwrite(STDOUT, "created voucher #{$payload->voucherId}\n");
exit(0);
