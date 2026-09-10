<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application;

/**
 * Runs a unit of work atomically. Application use cases depend on this port; the
 * database-backed implementation is
 * {@see \Gomrok\Shared\Infrastructure\Persistence\TransactionRunner}. Tests
 * supply a pass-through double.
 */
interface Transactions
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
