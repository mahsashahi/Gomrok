<?php

declare(strict_types=1);

/**
 * List a client's payments.
 *
 *   php bin/ListPayments.php --client=televika
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Payments\Application\PaymentDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:']);
if ($opts === false || !isset($opts['client'])) {
    fwrite(STDERR, "usage: php bin/ListPayments.php --client=<slug|id>\n");
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

$payments = $container->get(PaymentDirectory::class);
assert($payments instanceof PaymentDirectory);
$rows = $payments->forClient($client->id);

if ($rows === []) {
    fwrite(STDOUT, "no payments for client '{$clientRef}'\n");
    exit(0);
}

foreach ($rows as $p) {
    fwrite(STDOUT, sprintf(
        "#%-4d [%-20s] attempt=%-4d package=%-4d amount=%-8d %s\n",
        $p->id,
        $p->status,
        $p->checkoutAttemptId,
        $p->packageId,
        $p->amountMinor,
        $p->currencyCode,
    ));
    if ($p->errorCode !== null) {
        fwrite(STDOUT, "       error: {$p->errorCode} — {$p->errorMessage}\n");
    }
}

exit(0);
