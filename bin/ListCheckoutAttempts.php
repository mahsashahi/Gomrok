<?php

declare(strict_types=1);

/**
 * List a client's checkout attempts (pre-payment lifecycle tracking).
 *
 *   php bin/ListCheckoutAttempts.php --client=televika
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Clients\Application\ClientDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:']);
if ($opts === false || !isset($opts['client'])) {
    fwrite(STDERR, "usage: php bin/ListCheckoutAttempts.php --client=<slug|id>\n");
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

$attempts = $container->get(CheckoutAttemptDirectory::class);
assert($attempts instanceof CheckoutAttemptDirectory);
$rows = $attempts->forClient($client->id);

if ($rows === []) {
    fwrite(STDOUT, "no checkout attempts for client '{$clientRef}'\n");
    exit(0);
}

foreach ($rows as $a) {
    fwrite(STDOUT, sprintf(
        "#%-4d [%-24s] attempt=%-16s package=%-4d %s %s\n",
        $a->id,
        $a->status,
        $a->attemptReference,
        $a->packageId,
        $a->country,
        $a->currencyCode,
    ));
    if ($a->errorCode !== null) {
        fwrite(STDOUT, "       error: {$a->errorCode} — {$a->errorMessage}\n");
    }
}

exit(0);
