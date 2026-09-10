<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Application\Transactions;

/**
 * Runs the unit of work immediately — no real transaction. For use-case tests.
 */
final class SynchronousTransactions implements Transactions
{
    public function run(callable $work): mixed
    {
        return $work();
    }
}
