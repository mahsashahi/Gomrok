<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Infrastructure;

use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Application\SubscriptionSummary;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoSubscriptionDirectory implements SubscriptionDirectory
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findById(int $id): ?SubscriptionSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM subscriptions WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?SubscriptionSummary
    {
        $statement = $this->pdo->prepare('SELECT * FROM subscriptions WHERE checkout_attempt_id = :a');
        $statement->execute(['a' => $checkoutAttemptId]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    public function forClientUser(int $clientId, string $clientUserRef): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM subscriptions WHERE client_id = :c AND client_user_ref = :u ORDER BY created_at DESC',
        );
        $statement->execute(['c' => $clientId, 'u' => $clientUserRef]);

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
    private function toSummary(array $row): SubscriptionSummary
    {
        return new SubscriptionSummary(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::str($row['client_user_ref'] ?? ''),
            Row::int($row['checkout_attempt_id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::str($row['currency_code'] ?? ''),
            Row::int($row['amount_minor'] ?? null),
            Row::nullableStr($row['payment_method'] ?? null),
            Row::str($row['subscription_interval'] ?? ''),
            Row::str($row['status'] ?? 'active'),
            Row::nullableStr($row['trial_ends_at'] ?? null),
            Row::nullableStr($row['current_period_start'] ?? null),
            Row::nullableStr($row['current_period_end'] ?? null),
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            Row::str($row['created_at'] ?? ''),
            Row::nullableStr($row['updated_at'] ?? null),
        );
    }
}
