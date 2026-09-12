<?php

declare(strict_types=1);

/**
 * Record one raw provider call/response under a payment, and transition the
 * payment's status.
 *
 *   php bin/RecordProviderTransaction.php --client=televika --payment=1 --provider-account=1 \
 *       --kind=authorize --status-raw=succeeded --new-status=authorized [--method=card] \
 *       [--attempt-outcome=succeeded] [--request-json='{"amount":2900}'] [--response-json='{"id":"pi_1"}'] \
 *       [--error-code=] [--error-message=]
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionCommand;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionHandler;
use Gomrok\Modules\Payments\Application\RecordProviderTransaction\RecordProviderTransactionResult;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', [
    'client:', 'payment:', 'provider-account:', 'kind:', 'status-raw:', 'new-status:',
    'method::', 'attempt-outcome::', 'request-json::', 'response-json::', 'error-code::', 'error-message::',
]);
if (
    $opts === false
    || !isset($opts['client'], $opts['payment'], $opts['provider-account'], $opts['kind'], $opts['status-raw'], $opts['new-status'])
    || !is_string($opts['payment']) || !ctype_digit($opts['payment'])
    || !is_string($opts['provider-account']) || !ctype_digit($opts['provider-account'])
) {
    fwrite(STDERR, "usage: php bin/RecordProviderTransaction.php --client=<slug|id> --payment=<id> --provider-account=<id> --kind=<kind> --status-raw=<raw> --new-status=<status> [options]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;

$decodeJson = static function (?string $json): ?array {
    if ($json === null) {
        return null;
    }
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : null;
};

$container = ContainerFactory::create();
$directory = $container->get(ClientDirectory::class);
assert($directory instanceof ClientDirectory);
$clientRef = $asString($opts['client']);
$client = ctype_digit($clientRef) ? $directory->findById((int) $clientRef) : $directory->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$handler = $container->get(RecordProviderTransactionHandler::class);
assert($handler instanceof RecordProviderTransactionHandler);
$result = $handler->handle(new RecordProviderTransactionCommand(
    clientId: $client->id,
    paymentId: (int) $opts['payment'],
    providerAccountId: (int) $opts['provider-account'],
    kind: $asString($opts['kind']),
    providerStatusRaw: $asString($opts['status-raw']),
    newStatus: $asString($opts['new-status']),
    requestPayload: $decodeJson($strOrNull('request-json')),
    responsePayload: $decodeJson($strOrNull('response-json')),
    paymentMethod: $strOrNull('method'),
    attemptOutcome: $strOrNull('attempt-outcome'),
    errorCode: $strOrNull('error-code'),
    errorMessage: $strOrNull('error-message'),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof RecordProviderTransactionResult);
fwrite(STDOUT, "attempt #{$payload->paymentAttemptId} / transaction #{$payload->providerTransactionId} -> payment status={$payload->paymentStatus}\n");
exit(0);
