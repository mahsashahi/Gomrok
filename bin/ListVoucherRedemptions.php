<?php

declare(strict_types=1);

/**
 * List a voucher's redemptions (reserve/confirm/release history).
 *
 *   php bin/ListVoucherRedemptions.php --client=televika --voucher=1
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherRedemptionDirectory;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'voucher:']);
if ($opts === false || !isset($opts['client'], $opts['voucher']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])) {
    fwrite(STDERR, "usage: php bin/ListVoucherRedemptions.php --client=<slug|id> --voucher=<id>\n");
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

$vouchers = $container->get(VoucherRepository::class);
assert($vouchers instanceof VoucherRepository);
$voucherId = (int) $opts['voucher'];
$voucher = $vouchers->findById($voucherId);
if ($voucher === null || $voucher->clientId() !== $client->id) {
    fwrite(STDERR, "error: voucher {$voucherId} not found for this client\n");
    exit(1);
}

$redemptions = $container->get(VoucherRedemptionDirectory::class);
assert($redemptions instanceof VoucherRedemptionDirectory);
$rows = $redemptions->forVoucher($voucherId);

if ($rows === []) {
    fwrite(STDOUT, "no redemptions for voucher #{$voucherId}\n");
    exit(0);
}

foreach ($rows as $r) {
    fwrite(STDOUT, sprintf(
        "#%-4d [%-9s] attempt=%-20s user=%-14s price=%d discount=%d payable=%d %s\n",
        $r->id,
        $r->status,
        $r->attemptReference,
        $r->clientUserRef ?? '-',
        $r->priceMinor,
        $r->appliedDiscountMinor,
        $r->payableMinor,
        $r->currencyCode,
    ));
}

exit(0);
