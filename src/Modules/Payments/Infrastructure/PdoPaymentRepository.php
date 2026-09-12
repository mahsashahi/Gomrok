<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPaymentRepository implements PaymentRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(Payment $payment): void
    {
        if ($payment->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO payments
                    (client_id, checkout_attempt_id, client_user_ref, package_id, country, currency_code,
                     amount_minor, purchase_type, payment_method, subscription_interval, status,
                     error_code, error_message, created_at, updated_at)
                 VALUES
                    (:client_id, :checkout_attempt_id, :client_user_ref, :package_id, :country, :currency_code,
                     :amount_minor, :purchase_type, :payment_method, :subscription_interval, :status,
                     :error_code, :error_message, :created_at, :updated_at)',
            );
            $statement->execute($this->params($payment) + [
                'created_at' => $payment->createdAt()->format(self::DT),
                'updated_at' => $payment->updatedAt()?->format(self::DT),
            ]);
            $payment->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE payments SET status = :status, error_code = :error_code, error_message = :error_message, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $payment->id(),
            'status' => $payment->status()->value,
            'error_code' => $payment->errorCode(),
            'error_message' => $payment->errorMessage(),
            'updated_at' => $payment->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
        ]);
    }

    public function findById(int $id): ?Payment
    {
        $statement = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?Payment
    {
        $statement = $this->pdo->prepare('SELECT * FROM payments WHERE checkout_attempt_id = :id');
        $statement->execute(['id' => $checkoutAttemptId]);

        return $this->hydrate($statement->fetch());
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM payments WHERE client_id = :c ORDER BY id DESC');
        $statement->execute(['c' => $clientId]);

        $payments = [];
        while (($row = $statement->fetch()) !== false) {
            $payment = $this->hydrate($row);
            if ($payment !== null) {
                $payments[] = $payment;
            }
        }

        return $payments;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(Payment $payment): array
    {
        return [
            'client_id' => $payment->clientId(),
            'checkout_attempt_id' => $payment->checkoutAttemptId(),
            'client_user_ref' => $payment->clientUserRef(),
            'package_id' => $payment->packageId(),
            'country' => $payment->country(),
            'currency_code' => $payment->currencyCode(),
            'amount_minor' => $payment->amountMinor(),
            'purchase_type' => $payment->purchaseType()->value,
            'payment_method' => $payment->paymentMethod()?->value,
            'subscription_interval' => $payment->subscriptionInterval()?->value,
            'status' => $payment->status()->value,
            'error_code' => $payment->errorCode(),
            'error_message' => $payment->errorMessage(),
        ];
    }

    private function hydrate(mixed $row): ?Payment
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return Payment::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['checkout_attempt_id'] ?? null),
            Row::nullableStr($row['client_user_ref'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::str($row['country'] ?? ''),
            Row::str($row['currency_code'] ?? ''),
            Row::int($row['amount_minor'] ?? null),
            PurchaseType::from(Row::str($row['purchase_type'] ?? '')),
            PaymentMethod::tryFrom(Row::str($row['payment_method'] ?? '')),
            SubscriptionInterval::tryFrom(Row::str($row['subscription_interval'] ?? '')),
            PaymentStatus::from(Row::str($row['status'] ?? 'created')),
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
