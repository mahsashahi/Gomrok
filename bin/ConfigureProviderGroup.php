<?php

declare(strict_types=1);

/**
 * Set a provider group's country / purchase-type / method scope. The lists
 * fully replace whatever the group had.
 *
 *   php bin/ConfigureProviderGroup.php --client=televika --group=turkey \
 *       --country=TR --purchase-type=one_time_payment [--method=bank_hosted_card]
 *       [--name="Turkey"] [--currency=TRY | --clear-currency]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupCommand;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupHandler;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'group:', 'country::', 'purchase-type::', 'method::', 'name::', 'currency::', 'clear-currency']);
if ($opts === false || !isset($opts['client'], $opts['group'])) {
    fwrite(STDERR, "usage: php bin/ConfigureProviderGroup.php --client=<slug|id> --group=<slug> [--country=TR ...] [--purchase-type=one_time_payment ...] [--method=card ...] [--name=<name>] [--currency=TRY | --clear-currency]\n");
    exit(2);
}

$asList = static function (mixed $v): array {
    if (is_array($v)) {
        return array_values(array_filter($v, static fn ($x): bool => is_string($x) && $x !== ''));
    }

    return is_string($v) && $v !== '' ? [$v] : [];
};
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

$groups = $container->get(ProviderGroupRepository::class);
assert($groups instanceof ProviderGroupRepository);
$group = $groups->findByClientAndSlug($client->id, $asString($opts['group']));
if ($group === null) {
    fwrite(STDERR, "error: group '{$asString($opts['group'])}' not found for this client\n");
    exit(1);
}
$groupId = $group->id();
assert($groupId !== null);

$handler = $container->get(ConfigureProviderGroupHandler::class);
assert($handler instanceof ConfigureProviderGroupHandler);

$result = $handler->handle(new ConfigureProviderGroupCommand(
    groupId: $groupId,
    countries: $asList($opts['country'] ?? null),
    purchaseTypes: $asList($opts['purchase-type'] ?? null),
    methods: $asList($opts['method'] ?? null),
    name: ($name = $asString($opts['name'] ?? null)) !== '' ? $name : null,
    currencyCode: ($currency = $asString($opts['currency'] ?? null)) !== '' ? $currency : null,
    clearCurrency: array_key_exists('clear-currency', $opts),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "provider group '{$asString($opts['group'])}' configured\n");
exit(0);
