<?php

declare(strict_types=1);

/**
 * Reserve a voucher for a checkout attempt (requires pricing to already be
 * resolved).
 *
 *   php bin/ReserveCheckoutVoucher.php --client=televika --attempt=42 --code=WELCOME10
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherCommand;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherHandler;
use Gomrok\Modules\Checkout\Application\ReserveCheckoutVoucher\ReserveCheckoutVoucherResult;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'attempt:', 'code:', 'client-user::', 'first-purchase', 'not-first-purchase']);
if ($opts === false || !isset($opts['client'], $opts['attempt'], $opts['code'])) {
    fwrite(STDERR, "usage: php bin/ReserveCheckoutVoucher.php --client=<slug|id> --attempt=<ref|id> --code=<voucher> [options]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;

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

$attemptRef = $asString($opts['attempt']);
if (ctype_digit($attemptRef)) {
    $attemptId = (int) $attemptRef;
} else {
    $attempts = $container->get(CheckoutAttemptRepository::class);
    assert($attempts instanceof CheckoutAttemptRepository);
    $attempt = $attempts->findByAttemptReference($client->id, $attemptRef);
    if ($attempt === null) {
        fwrite(STDERR, "error: checkout attempt '{$attemptRef}' not found for this client\n");
        exit(1);
    }
    $attemptId = $attempt->id();
}
assert($attemptId !== null);

$handler = $container->get(ReserveCheckoutVoucherHandler::class);
assert($handler instanceof ReserveCheckoutVoucherHandler);
$result = $handler->handle(new ReserveCheckoutVoucherCommand(
    checkoutAttemptId: $attemptId,
    clientId: $client->id,
    voucherCode: $asString($opts['code']),
    clientUserRef: $strOrNull('client-user'),
    isFirstPurchase: $isFirstPurchase,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof ReserveCheckoutVoucherResult);
fwrite(STDOUT, "voucher redemption #{$payload->voucherRedemptionId}: payable={$payload->payableMinor}\n");
exit(0);
