<?php

declare(strict_types=1);

/**
 * Create a client and issue its first API key.
 *
 *   php bin/CreateClient.php --slug=televika --name="Televika" --currency=EUR \
 *       [--country=DE] [--timezone=Europe/Berlin] [--test]
 *
 * The API token is printed once and never again.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientCommand;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientHandler;
use Gomrok\Modules\Clients\Application\CreateClient\CreateClientResult;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['slug:', 'name:', 'currency:', 'country::', 'timezone::', 'test']);
if ($opts === false || !isset($opts['slug'], $opts['name'], $opts['currency'])) {
    fwrite(STDERR, "usage: php bin/CreateClient.php --slug=<slug> --name=<name> --currency=<ISO> [--country=<ISO2>] [--timezone=<tz>] [--test]\n");
    exit(2);
}

$slug = is_string($opts['slug']) ? $opts['slug'] : '';
$name = is_string($opts['name']) ? $opts['name'] : '';
$currency = is_string($opts['currency']) ? $opts['currency'] : '';
$country = isset($opts['country']) && is_string($opts['country']) && $opts['country'] !== '' ? $opts['country'] : null;
$timezone = isset($opts['timezone']) && is_string($opts['timezone']) && $opts['timezone'] !== '' ? $opts['timezone'] : 'UTC';
$prefix = array_key_exists('test', $opts) ? ApiKeyPrefix::Test : ApiKeyPrefix::Live;

$handler = ContainerFactory::create()->get(CreateClientHandler::class);
assert($handler instanceof CreateClientHandler);

$result = $handler->handle(new CreateClientCommand($slug, $name, $currency, $country, $timezone, $prefix));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof CreateClientResult);

fwrite(STDOUT, "client created\n");
fwrite(STDOUT, "  id:    {$payload->clientId}\n");
fwrite(STDOUT, "  slug:  {$payload->slug}\n");
fwrite(STDOUT, "  key id: {$payload->keyId}\n");
fwrite(STDOUT, "\n  API KEY (shown once — store it now):\n  {$payload->plaintextApiKey}\n");

exit(0);
