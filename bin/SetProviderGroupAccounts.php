<?php

declare(strict_types=1);

/**
 * Set a provider group's ordered provider-account list. Pass --account once per
 * account, in priority order (first = highest priority).
 *
 *   php bin/SetProviderGroupAccounts.php --client=televika --group=turkey \
 *       --account=ziraat-test [--account=stripe-test]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\ProviderGroupAccountInput;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsCommand;
use Gomrok\Modules\Providers\Application\SetProviderGroupAccounts\SetProviderGroupAccountsHandler;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'group:', 'account:']);
if ($opts === false || !isset($opts['client'], $opts['group'], $opts['account'])) {
    fwrite(STDERR, "usage: php bin/SetProviderGroupAccounts.php --client=<slug|id> --group=<slug> --account=<account-slug> [--account=<account-slug> ...]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$accountSlugs = is_array($opts['account'])
    ? array_values(array_filter($opts['account'], static fn ($x): bool => is_string($x) && $x !== ''))
    : [$asString($opts['account'])];

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

$accounts = $container->get(ProviderAccountDirectory::class);
assert($accounts instanceof ProviderAccountDirectory);

$entries = [];
$priority = 0;
foreach ($accountSlugs as $slug) {
    $summary = $accounts->find($client->id, $slug);
    if ($summary === null) {
        fwrite(STDERR, "error: provider account '{$slug}' not found for this client\n");
        exit(1);
    }
    $entries[] = new ProviderGroupAccountInput($summary->id, $priority);
    ++$priority;
}

$handler = $container->get(SetProviderGroupAccountsHandler::class);
assert($handler instanceof SetProviderGroupAccountsHandler);

$result = $handler->handle(new SetProviderGroupAccountsCommand($groupId, $entries));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, "provider group '{$asString($opts['group'])}' now routes to: " . implode(' > ', $accountSlugs) . "\n");
exit(0);
