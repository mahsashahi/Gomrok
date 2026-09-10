<?php

declare(strict_types=1);

/**
 * Revoke an API key by its key id.
 *
 *   php bin/RevokeClientApiKey.php --key-id=a1b2c3d4e5f6a7b8
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\RevokeApiKey\RevokeApiKeyCommand;
use Gomrok\Modules\Clients\Application\RevokeApiKey\RevokeApiKeyHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['key-id:']);
if ($opts === false || !isset($opts['key-id']) || !is_string($opts['key-id'])) {
    fwrite(STDERR, "usage: php bin/RevokeClientApiKey.php --key-id=<key_id>\n");
    exit(2);
}

$handler = ContainerFactory::create()->get(RevokeApiKeyHandler::class);
assert($handler instanceof RevokeApiKeyHandler);

$result = $handler->handle(new RevokeApiKeyCommand($opts['key-id']));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "api key {$opts['key-id']} revoked\n");

exit(0);
