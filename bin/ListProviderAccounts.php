<?php

declare(strict_types=1);

/**
 * List provider accounts (no secrets).
 *
 *   php bin/ListProviderAccounts.php [--client=<slug|id>]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client::']);
$container = ContainerFactory::create();

printf("%-5s  %-24s  %-12s  %-6s  %-9s  %-8s  %s\n", 'id', 'slug', 'type', 'mode', 'status', 'secret', 'markets');
printf("%s\n", str_repeat('-', 100));

$render = static function (ProviderAccountSummary $a): void {
    printf(
        "%-5d  %-24s  %-12s  %-6s  %-9s  ••••%-4s  %s | %s\n",
        $a->id,
        $a->slug,
        $a->providerTypeCode,
        $a->mode,
        $a->status,
        $a->secretLastFour,
        $a->countries === [] ? '-' : implode(',', $a->countries),
        $a->methods === [] ? '-' : implode(',', $a->methods),
    );
};

$count = 0;
$clientOpt = $opts['client'] ?? null;

if (is_string($clientOpt) && $clientOpt !== '') {
    $clients = $container->get(ClientDirectory::class);
    assert($clients instanceof ClientDirectory);
    $client = ctype_digit($clientOpt) ? $clients->findById((int) $clientOpt) : $clients->findBySlug($clientOpt);
    if ($client === null) {
        fwrite(STDERR, "error: client '{$clientOpt}' not found\n");
        exit(1);
    }

    $directory = $container->get(ProviderAccountDirectory::class);
    assert($directory instanceof ProviderAccountDirectory);
    foreach ($directory->forClient($client->id) as $account) {
        $render($account);
        ++$count;
    }
} else {
    $pdo = $container->get(PDO::class);
    assert($pdo instanceof PDO);
    $rows = $pdo->query('SELECT client_id FROM provider_accounts GROUP BY client_id');
    $directory = $container->get(ProviderAccountDirectory::class);
    assert($directory instanceof ProviderAccountDirectory);
    if ($rows !== false) {
        while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($directory->forClient(Row::int($row['client_id'] ?? null)) as $account) {
                $render($account);
                ++$count;
            }
        }
    }
}

printf("\n%d provider account(s)\n", $count);

exit(0);
