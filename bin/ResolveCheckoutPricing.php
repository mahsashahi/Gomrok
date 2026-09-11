<?php

declare(strict_types=1);

/**
 * Resolve a checkout attempt's price and freeze the pricing decision snapshot.
 *
 *   php bin/ResolveCheckoutPricing.php --client=televika --attempt=42
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingCommand;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingHandler;
use Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing\ResolveCheckoutPricingResult;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['client:', 'attempt:', 'device::', 'provider-account::', 'price-list::']);
if ($opts === false || !isset($opts['client'], $opts['attempt'])) {
    fwrite(STDERR, "usage: php bin/ResolveCheckoutPricing.php --client=<slug|id> --attempt=<ref|id> [--device=] [--provider-account=] [--price-list=]\n");
    exit(2);
}

$asString = static fn (mixed $v, string $default = ''): string => is_string($v) ? $v : $default;
$strOrNull = static fn (string $k) => is_string($opts[$k] ?? null) && $opts[$k] !== '' ? $opts[$k] : null;
$intOrNull = static fn (string $k): ?int => is_string($opts[$k] ?? null) && ctype_digit($opts[$k]) ? (int) $opts[$k] : null;

$container = ContainerFactory::create();
$directory = $container->get(ClientDirectory::class);
assert($directory instanceof ClientDirectory);
$clientRef = $asString($opts['client']);
$client = ctype_digit($clientRef) ? $directory->findById((int) $clientRef) : $directory->findBySlug($clientRef);
if ($client === null) {
    fwrite(STDERR, "error: client '{$clientRef}' not found\n");
    exit(1);
}

$attemptRef = $asString($opts['attempt']);
$attemptId = null;
if (ctype_digit($attemptRef)) {
    $attemptId = (int) $attemptRef;
} else {
    $attempts = $container->get(CheckoutAttemptRepository::class);
    assert($attempts instanceof CheckoutAttemptRepository);
    $attempt = $attempts->findByAttemptReference($client->id, $attemptRef);
    if ($attempt === null) {
        fwrite(STDERR, "error: checkout attempt '{$attemptRef}' not found for this client\n");
        exit(1);
    }
    $attemptId = $attempt->id();
}
assert($attemptId !== null);

$handler = $container->get(ResolveCheckoutPricingHandler::class);
assert($handler instanceof ResolveCheckoutPricingHandler);
$result = $handler->handle(new ResolveCheckoutPricingCommand(
    checkoutAttemptId: $attemptId,
    clientId: $client->id,
    deviceType: $strOrNull('device'),
    providerAccountId: $intOrNull('provider-account'),
    priceListId: $intOrNull('price-list'),
));

if ($result->isErr()) {
    $error = $result->error();
    fwrite(STDERR, "error [{$error->code}]: {$error->message}\n");
    exit(1);
}

$payload = $result->value();
assert($payload instanceof ResolveCheckoutPricingResult);
fwrite(STDOUT, "resolved price: {$payload->amountMinor} {$payload->currencyCode} (source={$payload->source})\n");
exit(0);
