<?php

declare(strict_types=1);

/**
 * Issue an additional API key for an existing client.
 *
 *   php bin/IssueClientApiKey.php --client=televika [--label="ci"] [--test]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyCommand;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyHandler;
use Gomrok\Modules\Clients\Application\IssueApiKey\IssueApiKeyResult;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'label::', 'test']);
if ($opts === false || !isset($opts['client']) || !is_string($opts['client'])) {
    fwrite(STDERR, "usage: php bin/IssueClientApiKey.php --client=<slug|id> [--label=<label>] [--test]\n");
    exit(2);
}

$container = ContainerFactory::create();

$directory = $container->get(ClientDirectory::class);
assert($directory instanceof ClientDirectory);

$clientRef = $opts['client'];
$client = ctype_digit($clientRef) ? $directory->findById((int) $clientRef) : $directory->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$label = isset($opts['label']) && is_string($opts['label']) && $opts['label'] !== '' ? $opts['label'] : null;
$prefix = array_key_exists('test', $opts) ? ApiKeyPrefix::Test : ApiKeyPrefix::Live;

$handler = $container->get(IssueApiKeyHandler::class);
assert($handler instanceof IssueApiKeyHandler);

$result = $handler->handle(new IssueApiKeyCommand($client->id, $prefix, $label));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof IssueApiKeyResult);

fwrite(STDOUT, "api key issued for client {$client->slug}\n");
fwrite(STDOUT, "  key id: {$payload->keyId}\n");
fwrite(STDOUT, "\n  API KEY (shown once — store it now):\n  {$payload->plaintextToken}\n");

exit(0);
