<?php

declare(strict_types=1);

/**
 * List clients (slug, name, status, active API-key count). No secrets.
 *
 *   php bin/ListClients.php
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Shared\Infrastructure\Persistence\Row;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = ContainerFactory::create()->get(PDO::class);
assert($pdo instanceof PDO);

$statement = $pdo->query(
    'SELECT c.id, c.slug, c.name, c.status,
            (SELECT COUNT(*) FROM client_api_keys k WHERE k.client_id = c.id AND k.status = \'active\') AS active_keys
       FROM clients c
      ORDER BY c.id',
);

if ($statement === false) {
    fwrite(STDERR, "error: query failed\n");
    exit(1);
}

printf("%-5s  %-24s  %-30s  %-9s  %s\n", 'id', 'slug', 'name', 'status', 'keys');
printf("%s\n", str_repeat('-', 90));

$count = 0;
while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
    if (!is_array($row)) {
        continue;
    }
    ++$count;
    printf(
        "%-5s  %-24s  %-30s  %-9s  %s\n",
        Row::str($row['id'] ?? ''),
        Row::str($row['slug'] ?? ''),
        substr(Row::str($row['name'] ?? ''), 0, 30),
        Row::str($row['status'] ?? ''),
        Row::str($row['active_keys'] ?? '0'),
    );
}

printf("\n%d client(s)\n", $count);

exit(0);
