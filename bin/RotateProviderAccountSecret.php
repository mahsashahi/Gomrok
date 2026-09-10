<?php

declare(strict_types=1);

/**
 * Rotate a provider account's secret key.
 *
 *   php bin/RotateProviderAccountSecret.php --client=televika --account=stripe-live [--secret-key=sk_live_...] [--public-key=pk_live_...]
 *
 * If --secret-key is omitted it is read from stdin. Never echoed back.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\RotateProviderAccountSecret\RotateProviderAccountSecretCommand;
use Gomrok\Modules\Providers\Application\RotateProviderAccountSecret\RotateProviderAccountSecretHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'account:', 'secret-key::', 'public-key::']);
if ($opts === false || !isset($opts['client'], $opts['account']) || !is_string($opts['client']) || !is_string($opts['account'])) {
    fwrite(STDERR, "usage: php bin/RotateProviderAccountSecret.php --client=<slug|id> --account=<account-slug> [--secret-key=<sk>] [--public-key=<pk>]\n");
    exit(2);
}

$asString = static fn (mixed $v): string => is_string($v) ? $v : '';

$secret = $asString($opts['secret-key'] ?? null);
if ($secret === '') {
    $stdin = stream_get_contents(STDIN);
    $secret = trim($stdin === false ? '' : $stdin);
}
if ($secret === '') {
    fwrite(STDERR, "error: no secret key\n");
    exit(2);
}

$container = ContainerFactory::create();

$clients = $container->get(ClientDirectory::class);
assert($clients instanceof ClientDirectory);
$client = ctype_digit($opts['client']) ? $clients->findById((int) $opts['client']) : $clients->findBySlug($opts['client']);
if ($client === null) {
    fwrite(STDERR, "error: client '{$opts['client']}' not found\n");
    exit(1);
}

$accounts = $container->get(ProviderAccountDirectory::class);
assert($accounts instanceof ProviderAccountDirectory);
$account = $accounts->find($client->id, $opts['account']);
if ($account === null) {
    fwrite(STDERR, "error: provider account '{$opts['account']}' not found for client\n");
    exit(1);
}

$handler = $container->get(RotateProviderAccountSecretHandler::class);
assert($handler instanceof RotateProviderAccountSecretHandler);

$publicKey = $asString($opts['public-key'] ?? null);
$result = $handler->handle(new RotateProviderAccountSecretCommand(
    accountId: $account->id,
    newSecretKey: $secret,
    newPublicKey: $publicKey !== '' ? $publicKey : null,
    replacePublicKey: $publicKey !== '',
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$lastFour = $result->value();
assert(is_string($lastFour));
fwrite(STDOUT, "secret rotated for {$opts['account']}; now ••••{$lastFour}\n");

exit(0);
