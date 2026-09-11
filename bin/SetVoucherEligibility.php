<?php

declare(strict_types=1);

/**
 * Full-replace a voucher's eligibility rules. Repeat --rule=dimension:value;
 * omit --rule entirely to clear all rules (unrestricted).
 *
 *   php bin/SetVoucherEligibility.php --client=televika --voucher=42 \
 *       --rule=country:DE --rule=country:AT --rule=payment_method:card
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Vouchers\Application\SetVoucherEligibility\SetVoucherEligibilityCommand;
use Gomrok\Modules\Vouchers\Application\SetVoucherEligibility\SetVoucherEligibilityHandler;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'voucher:', 'rule::']);
if ($opts === false || !isset($opts['client'], $opts['voucher']) || !is_string($opts['voucher']) || !ctype_digit($opts['voucher'])) {
    fwrite(STDERR, "usage: php bin/SetVoucherEligibility.php --client=<slug|id> --voucher=<id> [--rule=dimension:value ...]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;

$rawRules = $opts['rule'] ?? [];
$rawRules = is_array($rawRules) ? $rawRules : [$rawRules];
$rules = [];
foreach ($rawRules as $raw) {
    if (!is_string($raw) || !str_contains($raw, ':')) {
        fwrite(STDERR, "error: --rule must be 'dimension:value', got " . var_export($raw, true) . "\n");
        exit(2);
    }
    [$dimension, $value] = explode(':', $raw, 2);
    $rules[] = ['dimension' => $dimension, 'value' => $value];
}

$container = ContainerFactory::create();
$directory = $container->get(ClientDirectory::class);
assert($directory instanceof ClientDirectory);
$clientRef = $asString($opts['client']);
$client = ctype_digit($clientRef) ? $directory->findById((int) $clientRef) : $directory->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$handler = $container->get(SetVoucherEligibilityHandler::class);
assert($handler instanceof SetVoucherEligibilityHandler);
$result = $handler->handle(new SetVoucherEligibilityCommand(
    clientId: $client->id,
    voucherId: (int) $opts['voucher'],
    rules: $rules,
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

fwrite(STDOUT, 'set ' . count($rules) . " eligibility rule(s) for voucher #{$opts['voucher']}\n");
exit(0);
