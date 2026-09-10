<?php

declare(strict_types=1);

/**
 * Link a package to one of the client's provider accounts on the provider side.
 *
 *   php bin/LinkPackageProvider.php --client=televika --package=pro --account=stripe-live \
 *       [--name="Pro (Stripe)"] [--remote-id=prod_ABC] [--not-needed]
 *
 * `--remote-id` marks the definition synced. `--not-needed` marks it not_needed
 * (a provider with no product model). Provider-API creation lands per adapter.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\ChangePackageProviderSyncState\ChangePackageProviderSyncStateHandler;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderCommand;
use Gomrok\Modules\Packages\Application\LinkPackageProvider\LinkPackageProviderHandler;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\PackageProviderDefinitionDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:', 'account:', 'name::', 'remote-id::', 'not-needed']);
if ($opts === false || !isset($opts['client'], $opts['package'], $opts['account'])) {
    fwrite(STDERR, "usage: php bin/LinkPackageProvider.php --client=<slug|id> --package=<code> --account=<account-slug> [--name=<name>] [--remote-id=<id>] [--not-needed]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$fail = static function (string $code, string $message): never {
    fwrite(STDERR, "error [{$code}]: {$message}\n");
    exit(1);
};

$container = ContainerFactory::create();

$directory = $container->get(ClientDirectory::class);
assert($directory instanceof ClientDirectory);
$clientRef = $asString($opts['client']);
$client = ctype_digit($clientRef) ? $directory->findById((int) $clientRef) : $directory->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$packages = $container->get(PackageDirectory::class);
assert($packages instanceof PackageDirectory);
$package = $packages->find($client->id, $asString($opts['package']));
if ($package === null) {
    fwrite(STDERR, "error: package '{$asString($opts['package'])}' not found for this client\n");
    exit(1);
}

$accounts = $container->get(ProviderAccountDirectory::class);
assert($accounts instanceof ProviderAccountDirectory);
$account = $accounts->find($client->id, $asString($opts['account']));
if ($account === null) {
    fwrite(STDERR, "error: provider account '{$asString($opts['account'])}' not found for this client\n");
    exit(1);
}

$link = $container->get(LinkPackageProviderHandler::class);
assert($link instanceof LinkPackageProviderHandler);
$result = $link->handle(new LinkPackageProviderCommand(
    packageId: $package->id,
    providerAccountId: $account->id,
    providerSideName: ($n = $asString($opts['name'] ?? null)) !== '' ? $n : null,
    remoteId: ($r = $asString($opts['remote-id'] ?? null)) !== '' ? $r : null,
));
if ($result->isErr()) {
    $error = $result->error();
    $fail($error->code, $error->message);
}

if (array_key_exists('not-needed', $opts)) {
    $definitions = $container->get(PackageProviderDefinitionDirectory::class);
    assert($definitions instanceof PackageProviderDefinitionDirectory);
    $definition = $definitions->find($package->id, $account->id);
    if ($definition === null) {
        $fail('package.provider_definition_not_found', 'Definition was not created.');
    }

    $state = $container->get(ChangePackageProviderSyncStateHandler::class);
    assert($state instanceof ChangePackageProviderSyncStateHandler);
    $notNeeded = $state->markNotNeeded($definition->id);
    if ($notNeeded->isErr()) {
        $error = $notNeeded->error();
        $fail($error->code, $error->message);
    }
}

fwrite(STDOUT, "package '{$asString($opts['package'])}' linked to provider account '{$asString($opts['account'])}'\n");
exit(0);
