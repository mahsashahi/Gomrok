<?php

declare(strict_types=1);

/**
 * Dev-only CLI worker spawned by the load-test orchestrators (Phase 30A Q2 —
 * "lightweight PHP scripts... fire concurrent requests... against the local
 * DB", no new tooling dependency). Never called directly by an operator.
 *
 * Each invocation is a separate PHP process with its own real PDO connection
 * to the real local dev database, so several invocations launched close
 * together genuinely race for the same database row/lock — this is what
 * proves `PdoIdempotencyStore`/voucher-reservation concurrency safety for
 * real, not just against an in-memory test double.
 *
 * Usage:
 *   php ConcurrencyWorker.php idempotency-claim <clientId> <key> <fingerprint>
 *   php ConcurrencyWorker.php voucher-reserve <voucherId> <clientId> <attemptRef>
 *
 * Prints exactly one line to stdout: a machine-readable outcome the
 * orchestrator parses.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionCommand;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionResult;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Application\Idempotency\IdempotencyStore;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$mode = $argv[1] ?? '';
$container = ContainerFactory::create();

if ($mode === 'idempotency-claim') {
    $clientId = (int) $argv[2];
    $key = $argv[3];
    $fingerprint = $argv[4];

    $store = $container->get(IdempotencyStore::class);
    \assert($store instanceof IdempotencyStore);

    $now = new DateTimeImmutable();
    $result = $store->claim($clientId, $key, $fingerprint, $now, $now->modify('+1 hour'));

    echo $result === null ? "WON\n" : 'LOST:' . $result->status->value . "\n";
    exit(0);
}

if ($mode === 'voucher-reserve') {
    $voucherId = (int) $argv[2];
    $clientId = (int) $argv[3];
    $attemptRef = $argv[4];

    // Re-read the voucher through the real repository first so the handler's
    // own findByIdForUpdate() call is the one that actually races — this
    // process's very first statement against `vouchers` is the lock attempt.
    $vouchers = $container->get(VoucherRepository::class);
    \assert($vouchers instanceof VoucherRepository);
    if ($vouchers->findByIdForUpdate($voucherId) === null) {
        echo "ERROR:voucher_not_found\n";
        exit(1);
    }

    $handler = $container->get(ReserveVoucherRedemptionHandler::class);
    \assert($handler instanceof ReserveVoucherRedemptionHandler);

    $result = $handler->handle(new ReserveVoucherRedemptionCommand(
        clientId: $clientId,
        voucherId: $voucherId,
        attemptReference: $attemptRef,
        currencyCode: 'EUR',
        priceMinor: 1000,
        country: 'DE',
    ));

    if ($result->isErr()) {
        echo 'LOST:' . $result->error()->code . "\n";
        exit(0);
    }

    $value = $result->value();
    \assert($value instanceof ReserveVoucherRedemptionResult);
    echo 'WON:' . $value->redemptionId . "\n";
    exit(0);
}

fwrite(STDERR, "unknown mode '{$mode}'\n");
exit(2);
