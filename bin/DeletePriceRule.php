<?php

declare(strict_types=1);

/**
 * Delete a price rule by id.
 *
 *   php bin/DeletePriceRule.php --client=televika --rule=42
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\DeletePriceRule\DeletePriceRuleHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'rule:']);
if ($opts === false || !isset($opts['client'], $opts['rule']) || !is_string($opts['rule']) || !ctype_digit($opts['rule'])) {
    fwrite(STDERR, "usage: php bin/DeletePriceRule.php --client=<slug|id> --rule=<id>\n");
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

$handler = $container->get(DeletePriceRuleHandler::class);
assert($handler instanceof DeletePriceRuleHandler);
$result = $handler->handle((int) $opts['rule'], $client->id);

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "price rule #{$opts['rule']} deleted\n");
exit(0);
