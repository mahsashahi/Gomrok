<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Infrastructure;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Checkout\Application\CheckoutAttemptSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoCheckoutAttemptDirectory implements CheckoutAttemptDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE client_id = :c ORDER BY id DESC');
        $statement->execute(['c' => $clientId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $out[] = $this->toSummary($row);
            }
        }

        return $out;
    }

    public function findByAttemptReference(int $clientId, string $attemptReference): ?CheckoutAttemptSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE client_id = :c AND attempt_reference = :ref');
        $statement->execute(['c' => $clientId, 'ref' => $attemptReference]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): CheckoutAttemptSummary
    {
        return new CheckoutAttemptSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::nullableStr($row['client_user_ref'] ?? null),
            Row::str($row['attempt_reference'] ?? ''),
            Row::int($row['package_id'] ?? null),
            Row::str($row['country'] ?? ''),
            Row::str($row['currency_code'] ?? ''),
            Row::nullableStr($row['purchase_type'] ?? null),
            Row::nullableStr($row['payment_method'] ?? null),
            Row::nullableStr($row['subscription_interval'] ?? null),
            Row::str($row['status'] ?? 'started'),
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            Row::str($row['created_at'] ?? ''),
            Row::nullableStr($row['updated_at'] ?? null),
        );
    }
}
