<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Infrastructure;

use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Modules\Payments\Application\PaymentSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPaymentDirectory implements PaymentDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?PaymentSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?PaymentSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM payments WHERE checkout_attempt_id = :id');
        $statement->execute(['id' => $checkoutAttemptId]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM payments WHERE client_id = :c ORDER BY id DESC');
        $statement->execute(['c' => $clientId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $out[] = $this->toSummary($row);
            }
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): PaymentSummary
    {
        return new PaymentSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['checkout_attempt_id'] ?? null),
            Row::nullableStr($row['client_user_ref'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::str($row['country'] ?? ''),
            Row::str($row['currency_code'] ?? ''),
            Row::int($row['amount_minor'] ?? null),
            Row::str($row['purchase_type'] ?? ''),
            Row::nullableStr($row['payment_method'] ?? null),
            Row::nullableStr($row['subscription_interval'] ?? null),
            Row::str($row['status'] ?? 'created'),
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            Row::str($row['created_at'] ?? ''),
            Row::nullableStr($row['updated_at'] ?? null),
        );
    }
}
