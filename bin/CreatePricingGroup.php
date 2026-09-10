<?php

declare(strict_types=1);

/**
 * Define a pricing group for a client.
 *
 *   php bin/CreatePricingGroup.php --client=televika --name="DACH" --currency=EUR \
 *       [--slug=dach] [--priority=1] [--device=web|ios|android] [--default]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupCommand;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler;
use Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'name:', 'currency:', 'slug::', 'priority::', 'device::', 'default']);
if ($opts === false || !isset($opts['client'], $opts['name'], $opts['currency'])) {
    fwrite(STDERR, "usage: php bin/CreatePricingGroup.php --client=<slug|id> --name=<name> --currency=<ISO> [--slug=<slug>] [--priority=N] [--device=web|ios|android] [--default]\n");
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

$handler = $container->get(CreatePricingGroupHandler::class);
assert($handler instanceof CreatePricingGroupHandler);

$priorityRaw = $asString($opts['priority'] ?? null);
$result = $handler->handle(new CreatePricingGroupCommand(
    clientId: $client->id,
    name: $asString($opts['name']),
    currency: $asString($opts['currency']),
    isDefault: array_key_exists('default', $opts),
    slug: ($s = $asString($opts['slug'] ?? null)) !== '' ? $s : null,
    deviceType: ($d = $asString($opts['device'] ?? null)) !== '' ? $d : null,
    priority: ctype_digit($priorityRaw) ? (int) $priorityRaw : 100,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreatePricingGroupResult);
fwrite(STDOUT, "pricing group created: #{$payload->groupId} {$payload->slug}" . ($payload->isDefault ? ' (default)' : '') . "\n");
exit(0);
