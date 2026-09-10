<?php

declare(strict_types=1);

/**
 * Add (or rotate) an inbound endpoint for a provider account.
 *
 *   php bin/AddProviderAccountEndpoint.php --client=televika --account=stripe-live \
 *       --kind=webhook [--signing-secret=whsec_...]
 *
 * Prints the generated token + inbound path once.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint\AddProviderAccountEndpointCommand;
use Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint\AddProviderAccountEndpointHandler;
use Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint\AddProviderAccountEndpointResult;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'account:', 'kind:', 'signing-secret::']);
if ($opts === false || !isset($opts['client'], $opts['account'], $opts['kind'])
    || !is_string($opts['client']) || !is_string($opts['account']) || !is_string($opts['kind'])) {
    fwrite(STDERR, "usage: php bin/AddProviderAccountEndpoint.php --client=<slug|id> --account=<account-slug> --kind=<webhook|callback|return> [--signing-secret=<s>]\n");
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

$handler = $container->get(AddProviderAccountEndpointHandler::class);
assert($handler instanceof AddProviderAccountEndpointHandler);

$signing = $opts['signing-secret'] ?? null;
$result = $handler->handle(new AddProviderAccountEndpointCommand(
    accountId: $account->id,
    kind: $opts['kind'],
    signingSecret: is_string($signing) && $signing !== '' ? $signing : null,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof AddProviderAccountEndpointResult);

fwrite(STDOUT, "endpoint added ({$payload->kind})\n");
if ($payload->token !== null) {
    fwrite(STDOUT, "  token:        {$payload->token}\n");
    fwrite(STDOUT, "  inbound path: {$payload->inboundPath}\n");
}

exit(0);
