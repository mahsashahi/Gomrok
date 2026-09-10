<?php

declare(strict_types=1);

/**
 * Edit a package's name / description / metadata (not its code or availability).
 *
 *   php bin/UpdatePackage.php --client=televika --package=pro [--name="Pro Plus"]
 *       [--description="..." | --clear-description] [--disable | --enable]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\ChangePackageStatus\ChangePackageStatusHandler;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageCommand;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageHandler;
use Gomrok\Shared\Domain\Result;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'package:', 'name::', 'description::', 'clear-description', 'disable', 'enable']);
if ($opts === false || !isset($opts['client'], $opts['package'])) {
    fwrite(STDERR, "usage: php bin/UpdatePackage.php --client=<slug|id> --package=<code> [--name=<name>] [--description=<text> | --clear-description] [--disable | --enable]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$fail = static function (Result $result): void {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
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

$updates = new UpdatePackageCommand(
    packageId: $package->id,
    name: ($n = $asString($opts['name'] ?? null)) !== '' ? $n : null,
    description: ($d = $asString($opts['description'] ?? null)) !== '' ? $d : null,
    clearDescription: array_key_exists('clear-description', $opts),
);
if ($updates->name !== null || $updates->description !== null || $updates->clearDescription) {
    $handler = $container->get(UpdatePackageHandler::class);
    assert($handler instanceof UpdatePackageHandler);
    $result = $handler->handle($updates);
    if ($result->isErr()) {
        $fail($result);
    }
}

if (array_key_exists('disable', $opts) || array_key_exists('enable', $opts)) {
    $status = $container->get(ChangePackageStatusHandler::class);
    assert($status instanceof ChangePackageStatusHandler);
    $result = array_key_exists('disable', $opts) ? $status->disable($package->id) : $status->enable($package->id);
    if ($result->isErr()) {
        $fail($result);
    }
}

fwrite(STDOUT, "package '{$asString($opts['package'])}' updated\n");
exit(0);
