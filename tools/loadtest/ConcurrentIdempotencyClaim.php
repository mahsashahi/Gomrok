<?php

declare(strict_types=1);

/**
 * Load/soak test (Phase 30A Q2, user-specified: lightweight PHP scripts, no
 * new tooling dependency) — proves `PdoIdempotencyStore::claim()` is
 * concurrency-safe against the REAL local MySQL, not just against the
 * in-memory test double `IdempotencyMiddlewareTest.php` already covers.
 *
 * Spawns N real, separate PHP processes (each its own PDO connection) that
 * all race to claim the exact same (client_id, idempotency_key) at once.
 * Exactly one must win; every other must see it as already `processing`.
 *
 * Usage: php ConcurrentIdempotencyClaim.php [concurrency=20]
 *
 * Dev-only. Writes and then deletes one throwaway row in `idempotency_keys`.
 */

use Gomrok\Bootstrap\ContainerFactory;
use Gomrok\Config\Settings;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$concurrency = isset($argv[1]) ? max(2, (int) $argv[1]) : 20;
$clientId = 1; // local-dev
$key = 'loadtest-' . bin2hex(random_bytes(8));
$fingerprint = hash('sha256', 'loadtest-fixed-fingerprint');
$workerScript = __DIR__ . '/ConcurrencyWorker.php';

echo "Concurrent idempotency claim: {$concurrency} processes racing for key={$key}\n";

/** @var list<resource> $processes */
$processes = [];
/** @var list<array{0: resource, 1: resource}> $pipes */
$pipesByProcess = [];

$start = microtime(true);
for ($i = 0; $i < $concurrency; ++$i) {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['php', $workerScript, 'idempotency-claim', (string) $clientId, $key, $fingerprint],
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

    if ($out === 'WON') {
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

// Clean up the throwaway row.
$settings = Settings::fromEnvironment(dirname(__DIR__, 2));
$pdo = new PDO($settings->database->dsn(), $settings->database->user, $settings->database->password);
$pdo->prepare('DELETE FROM idempotency_keys WHERE client_id = :client_id AND idempotency_key = :key')
    ->execute(['client_id' => $clientId, 'key' => $key]);

if ($won !== 1 || $errors !== 0) {
    fwrite(STDERR, "FAIL: expected exactly 1 winner and 0 errors\n");
    exit(1);
}

echo "PASS: exactly one process claimed the key; all others correctly saw it as in-flight.\n";
exit(0);
