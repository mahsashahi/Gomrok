<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use Gomrok\Shared\Application\Transactions;
use PDO;
use PDOException;
use Throwable;

/**
 * Runs a unit of work inside a single database transaction. A nested call joins
 * the outer transaction rather than starting a new one.
 */
final readonly class TransactionRunner implements Transactions
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $work();
        }

        $this->pdo->beginTransaction();

        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException) {
                // no active transaction to roll back — keep the original error
            }

            throw $e;
        }
    }
}
