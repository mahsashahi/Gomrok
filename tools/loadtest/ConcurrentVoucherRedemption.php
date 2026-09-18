<?php

declare(strict_types=1);

/**
 * Load/soak test (Phase 30A Q2, user-specified: lightweight PHP scripts, no
 * new tooling dependency) — proves voucher redemption is concurrency-safe
 * against the REAL local MySQL (CLAUDE.md: "Voucher validation and
 * redemption must be safe under concurrent requests").
 *
 * Spawns N real, separate PHP processes that all race to reserve the same
 * voucher, which must have a global usage limit of 1.
 * `ReserveVoucherRedemptionHandler` holds a `FOR UPDATE` lock on the
 * `vouchers` row for exactly this reason — this proves it holds under
 * genuine concurrent MySQL access, not just against the in-memory
 * `InMemoryVoucherRepository` test double.
 *
 * Usage:
 *   Create the throwaway voucher first with the existing CLI tools (not this
 *   script — no need to reinvent voucher creation):
 *     php bin/CreateVoucher.php --client=local-dev --code=LOADTEST1 --name="Load test"
 *     php bin/SetVoucherUsageLimits.php --client=local-dev --voucher=<id> --max-total=1
 *   Then:
 *     php ConcurrentVoucherRedemption.php --run=<voucherId> [concurrency=20]
 *     php ConcurrentVoucherRedemption.php --tear-down=<voucherId>
 *
 * Dev-only.
 */

use Gomrok\Bootstrap\ContainerFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const CLIENT_ID = 1; // local-dev

$container = ContainerFactory::create();

$mode = $argv[1] ?? '';

if (str_starts_with($mode, '--run=')) {
    $voucherId = (int) substr($mode, \strlen('--run='));
    $concurrency = isset($argv[2]) ? max(2, (int) $argv[2]) : 20;
    $workerScript = __DIR__ . '/ConcurrencyWorker.php';

    echo "Concurrent voucher reservation: {$concurrency} processes racing for voucher #{$voucherId} (usage limit 1)\n";

    /** @var list<resource> $processes */
    $processes = [];
    /** @var list<array<int, resource>> $pipesByProcess */
    $pipesByProcess = [];

    $start = microtime(true);
    for ($i = 0; $i < $concurrency; ++$i) {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(
            ['php', $workerScript, 'voucher-reserve', (string) $voucherId, (string) CLIENT_ID, 'loadtest-attempt-' . $i],
            $descriptors,
            $pipes,
        );
        if ($process === false) {
            fwrite(STDERR, "failed to spawn worker {$i}\n");
            exit(1);
        }
        $processes[] = $process;
        $pipesByProcess[] = $pipes;
    }

    $won = 0;
    $lost = 0;
    $errors = 0;
    foreach ($processes as $i => $process) {
        $stdout = $pipesByProcess[$i][1];
        $stderr = $pipesByProcess[$i][2];
        $out = trim((string) stream_get_contents($stdout));
        $err = trim((string) stream_get_contents($stderr));
        fclose($stdout);
        fclose($stderr);
        proc_close($process);

        if (str_starts_with($out, 'WON:')) {
            ++$won;
        } elseif (str_starts_with($out, 'LOST:')) {
            ++$lost;
        } else {
            ++$errors;
            fwrite(STDERR, "worker {$i} unexpected output: '{$out}' stderr: '{$err}'\n");
        }
    }
    $elapsed = microtime(true) - $start;

    echo sprintf("Results: won=%d lost=%d errors=%d elapsed=%.2fs\n", $won, $lost, $errors, $elapsed);

    if ($won !== 1 || $errors !== 0) {
        fwrite(STDERR, "FAIL: expected exactly 1 successful reservation and 0 errors (usage limit is 1)\n");
        exit(1);
    }

    echo "PASS: exactly one process reserved the voucher; every other correctly hit the usage limit.\n";
    exit(0);
}

if (str_starts_with($mode, '--tear-down=')) {
    $voucherId = (int) substr($mode, \strlen('--tear-down='));
    $settings = $container->get(Gomrok\Config\Settings::class);
    \assert($settings instanceof Gomrok\Config\Settings);
    $pdo = new PDO($settings->database->dsn(), $settings->database->user, $settings->database->password);
    $pdo->prepare('DELETE FROM voucher_decision_snapshots WHERE voucher_id = :id')->execute(['id' => $voucherId]);
    $pdo->prepare('DELETE FROM voucher_redemptions WHERE voucher_id = :id')->execute(['id' => $voucherId]);
    $pdo->prepare('DELETE FROM vouchers WHERE id = :id')->execute(['id' => $voucherId]);
    echo "torn down voucher #{$voucherId}\n";
    exit(0);
}

fwrite(STDERR, "usage: --set-up | --run=<voucherId> [concurrency] | --tear-down=<voucherId>\n");
exit(2);
