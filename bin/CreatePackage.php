<?php

declare(strict_types=1);

/**
 * Add a package to a client's catalogue.
 *
 *   php bin/CreatePackage.php --client=televika --code=pro --name="Pro" [--description="..."]
 *
 * Availability is set separately with bin/SetPackageAvailability.php — a new
 * package is available everywhere until restricted.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageCommand;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageHandler;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'code:', 'name:', 'description::']);
if ($opts === false || !isset($opts['client'], $opts['code'], $opts['name'])) {
    fwrite(STDERR, "usage: php bin/CreatePackage.php --client=<slug|id> --code=<code> --name=<name> [--description=<text>]\n");
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

$handler = $container->get(CreatePackageHandler::class);
assert($handler instanceof CreatePackageHandler);

$result = $handler->handle(new CreatePackageCommand(
    clientId: $client->id,
    code: $asString($opts['code']),
    name: $asString($opts['name']),
    description: ($d = $asString($opts['description'] ?? null)) !== '' ? $d : null,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreatePackageResult);

fwrite(STDOUT, "package created\n");
fwrite(STDOUT, "  id:   {$payload->packageId}\n");
fwrite(STDOUT, "  code: {$payload->code}\n");

exit(0);
