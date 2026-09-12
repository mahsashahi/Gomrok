<?php

declare(strict_types=1);

/**
 * Link a client user to a durable customer identity on one provider's side.
 *
 *   php bin/LinkProviderCustomer.php --client=televika --provider-account=1 --client-user=user-1 --provider-customer-id=cus_123
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Payments\Application\LinkProviderCustomer\LinkProviderCustomerCommand;
use Gomrok\Modules\Payments\Application\LinkProviderCustomer\LinkProviderCustomerHandler;
use Gomrok\Modules\Payments\Application\LinkProviderCustomer\LinkProviderCustomerResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'provider-account:', 'client-user:', 'provider-customer-id:']);
if (
    $opts === false
    || !isset($opts['client'], $opts['provider-account'], $opts['client-user'], $opts['provider-customer-id'])
    || !is_string($opts['provider-account']) || !ctype_digit($opts['provider-account'])
) {
    fwrite(STDERR, "usage: php bin/LinkProviderCustomer.php --client=<slug|id> --provider-account=<id> --client-user=<ref> --provider-customer-id=<id>\n");
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

$handler = $container->get(LinkProviderCustomerHandler::class);
assert($handler instanceof LinkProviderCustomerHandler);
$result = $handler->handle(new LinkProviderCustomerCommand(
    clientId: $client->id,
    providerAccountId: (int) $opts['provider-account'],
    clientUserRef: $asString($opts['client-user']),
    providerCustomerId: $asString($opts['provider-customer-id']),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof LinkProviderCustomerResult);
fwrite(STDOUT, "provider customer row #{$payload->providerCustomerRowId}\n");
exit(0);
