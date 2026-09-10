<?php

declare(strict_types=1);

/**
 * Connect a provider account for a client.
 *
 *   php bin/CreateProviderAccount.php --client=televika --provider-type=stripe \
 *       --mode=live --name="Televika Stripe" --public-key=pk_live_... --secret-key=sk_live_... \
 *       [--slug=stripe-live] [--country=DE --country=NL] [--method=card --method=sepa_direct_debit]
 *
 * If --secret-key is omitted the secret is read from stdin. It is never echoed back.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountCommand;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountHandler;
use Gomrok\Modules\Providers\Application\CreateProviderAccount\CreateProviderAccountResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'provider-type:', 'mode:', 'name:', 'public-key::', 'secret-key::', 'slug::', 'country::', 'method::']);
if ($opts === false || !isset($opts['client'], $opts['provider-type'], $opts['mode'], $opts['name'])) {
    fwrite(STDERR, "usage: php bin/CreateProviderAccount.php --client=<slug|id> --provider-type=<code> --mode=<live|test> --name=<name> [--public-key=<pk>] [--secret-key=<sk>] [--slug=<slug>] [--country=DE ...] [--method=card ...]\n");
    exit(2);
}

$asList = static function (mixed $v): array {
    if (is_array($v)) {
        return array_values(array_filter($v, static fn ($x): bool => is_string($x) && $x !== ''));
    }

    return is_string($v) && $v !== '' ? [$v] : [];
};
$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;

$secret = $asString($opts['secret-key'] ?? null);
if ($secret === '') {
    $stdin = stream_get_contents(STDIN);
    $secret = trim($stdin === false ? '' : $stdin);
}
if ($secret === '') {
    fwrite(STDERR, "error: no secret key (pass --secret-key=... or pipe it on stdin)\n");
    exit(2);
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

$handler = $container->get(CreateProviderAccountHandler::class);
assert($handler instanceof CreateProviderAccountHandler);

$result = $handler->handle(new CreateProviderAccountCommand(
    clientId: $client->id,
    providerTypeCode: $asString($opts['provider-type']),
    mode: $asString($opts['mode']),
    name: $asString($opts['name']),
    secretKey: $secret,
    publicKey: ($pk = $asString($opts['public-key'] ?? null)) !== '' ? $pk : null,
    slug: ($slug = $asString($opts['slug'] ?? null)) !== '' ? $slug : null,
    countries: $asList($opts['country'] ?? null),
    methods: $asList($opts['method'] ?? null),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreateProviderAccountResult);

fwrite(STDOUT, "provider account created\n");
fwrite(STDOUT, "  id:     {$payload->accountId}\n");
fwrite(STDOUT, "  slug:   {$payload->slug}\n");
fwrite(STDOUT, "  mode:   {$payload->mode}\n");
fwrite(STDOUT, "  secret: ••••{$payload->secretLastFour} (stored encrypted)\n");

exit(0);
