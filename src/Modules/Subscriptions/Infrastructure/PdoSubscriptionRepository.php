<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionStatus;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoSubscriptionRepository implements SubscriptionRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(Subscription $subscription): void
    {
        if ($subscription->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO subscriptions
                    (client_id, client_user_ref, checkout_attempt_id, package_id, provider_account_id,
                     currency_code, amount_minor, payment_method, subscription_interval, status,
                     trial_ends_at, current_period_start, current_period_end, error_code, error_message,
                     created_at, updated_at)
                 VALUES
                    (:client_id, :client_user_ref, :checkout_attempt_id, :package_id, :provider_account_id,
                     :currency_code, :amount_minor, :payment_method, :subscription_interval, :status,
                     :trial_ends_at, :current_period_start, :current_period_end, :error_code, :error_message,
                     :created_at, :updated_at)',
            );
            $statement->execute($this->bindings($subscription));
            $subscription->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE subscriptions SET
                status = :status, current_period_start = :current_period_start,
                current_period_end = :current_period_end, error_code = :error_code,
                error_message = :error_message, updated_at = :updated_at
             WHERE id = :id',
        );
        $bindings = $this->bindings($subscription);
        $statement->execute([
            'id' => $subscription->id(),
            'status' => $bindings['status'],
            'current_period_start' => $bindings['current_period_start'],
            'current_period_end' => $bindings['current_period_end'],
            'error_code' => $bindings['error_code'],
            'error_message' => $bindings['error_message'],
            'updated_at' => $bindings['updated_at'],
        ]);
    }

    public function findById(int $id): ?Subscription
    {
        $statement = $this->pdo->prepare('SELECT * FROM subscriptions WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?Subscription
    {
        $statement = $this->pdo->prepare('SELECT * FROM subscriptions WHERE checkout_attempt_id = :a');
        $statement->execute(['a' => $checkoutAttemptId]);

        return $this->hydrate($statement->fetch());
    }

    public function forClientUser(int $clientId, string $clientUserRef): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM subscriptions WHERE client_id = :c AND client_user_ref = :u ORDER BY created_at DESC',
        );
        $statement->execute(['c' => $clientId, 'u' => $clientUserRef]);

        $subscriptions = [];
        while (($row = $statement->fetch()) !== false) {
            $subscription = $this->hydrate($row);
            if ($subscription !== null) {
                $subscriptions[] = $subscription;
            }
        }

        return $subscriptions;
    }

    /**
     * @return array<string, mixed>
     */
    private function bindings(Subscription $subscription): array
    {
        return [
            'client_id' => $subscription->clientId(),
            'client_user_ref' => $subscription->clientUserRef(),
            'checkout_attempt_id' => $subscription->checkoutAttemptId(),
            'package_id' => $subscription->packageId(),
            'provider_account_id' => $subscription->providerAccountId(),
            'currency_code' => $subscription->currencyCode(),
            'amount_minor' => $subscription->amountMinor(),
            'payment_method' => $subscription->paymentMethod()?->value,
            'subscription_interval' => $subscription->interval()->value,
            'status' => $subscription->status()->value,
            'trial_ends_at' => $subscription->trialEndsAt()?->format(self::DT),
            'current_period_start' => $subscription->currentPeriodStart()?->format(self::DT),
            'current_period_end' => $subscription->currentPeriodEnd()?->format(self::DT),
            'error_code' => $subscription->errorCode(),
            'error_message' => $subscription->errorMessage(),
            'created_at' => $subscription->createdAt()->format(self::DT),
            'updated_at' => $subscription->updatedAt()?->format(self::DT),
        ];
    }

    private function hydrate(mixed $row): ?Subscription
    {
        if (!\is_array($row)) {
            return null;
        }

        $paymentMethod = Row::nullableStr($row['payment_method'] ?? null);
        $trialEndsAt = Row::nullableStr($row['trial_ends_at'] ?? null);
        $periodStart = Row::nullableStr($row['current_period_start'] ?? null);
        $periodEnd = Row::nullableStr($row['current_period_end'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return Subscription::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::str($row['client_user_ref'] ?? ''),
            Row::int($row['checkout_attempt_id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::str($row['currency_code'] ?? ''),
            Row::int($row['amount_minor'] ?? null),
            $paymentMethod !== null ? PaymentMethod::from($paymentMethod) : null,
            SubscriptionInterval::from(Row::str($row['subscription_interval'] ?? 'monthly')),
            SubscriptionStatus::from(Row::str($row['status'] ?? 'active')),
            $trialEndsAt !== null ? new DateTimeImmutable($trialEndsAt) : null,
            $periodStart !== null ? new DateTimeImmutable($periodStart) : null,
            $periodEnd !== null ? new DateTimeImmutable($periodEnd) : null,
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
