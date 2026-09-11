<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoCheckoutAttemptRepository implements CheckoutAttemptRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(CheckoutAttempt $attempt): void
    {
        if ($attempt->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO checkout_attempts
                    (client_id, client_user_ref, attempt_reference, package_id, country, currency_code,
                     purchase_type, payment_method, subscription_interval, status, error_code, error_message,
                     created_at, updated_at, abandoned_at, expired_at)
                 VALUES
                    (:client_id, :client_user_ref, :attempt_reference, :package_id, :country, :currency_code,
                     :purchase_type, :payment_method, :subscription_interval, :status, :error_code, :error_message,
                     :created_at, :updated_at, :abandoned_at, :expired_at)',
            );
            $statement->execute($this->params($attempt) + [
                'created_at' => $attempt->createdAt()->format(self::DT),
                'updated_at' => $attempt->updatedAt()?->format(self::DT),
            ]);
            $attempt->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE checkout_attempts SET
                status = :status, error_code = :error_code, error_message = :error_message,
                updated_at = :updated_at, abandoned_at = :abandoned_at, expired_at = :expired_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $attempt->id(),
            'status' => $attempt->status()->value,
            'error_code' => $attempt->errorCode(),
            'error_message' => $attempt->errorMessage(),
            'updated_at' => $attempt->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
            'abandoned_at' => $attempt->abandonedAt()?->format(self::DT),
            'expired_at' => $attempt->expiredAt()?->format(self::DT),
        ]);
    }

    public function findById(int $id): ?CheckoutAttempt
    {
        $statement = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findByAttemptReference(int $clientId, string $attemptReference): ?CheckoutAttempt
    {
        $statement = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE client_id = :c AND attempt_reference = :ref');
        $statement->execute(['c' => $clientId, 'ref' => $attemptReference]);

        return $this->hydrate($statement->fetch());
    }

    public function forClient(int $clientId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM checkout_attempts WHERE client_id = :c ORDER BY id DESC');
        $statement->execute(['c' => $clientId]);

        $attempts = [];
        while (($row = $statement->fetch()) !== false) {
            $attempt = $this->hydrate($row);
            if ($attempt !== null) {
                $attempts[] = $attempt;
            }
        }

        return $attempts;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function params(CheckoutAttempt $attempt): array
    {
        return [
            'client_id' => $attempt->clientId(),
            'client_user_ref' => $attempt->clientUserRef(),
            'attempt_reference' => $attempt->attemptReference(),
            'package_id' => $attempt->packageId(),
            'country' => $attempt->country(),
            'currency_code' => $attempt->currencyCode(),
            'purchase_type' => $attempt->purchaseType()?->value,
            'payment_method' => $attempt->paymentMethod()?->value,
            'subscription_interval' => $attempt->subscriptionInterval()?->value,
            'status' => $attempt->status()->value,
            'error_code' => $attempt->errorCode(),
            'error_message' => $attempt->errorMessage(),
            'abandoned_at' => $attempt->abandonedAt()?->format(self::DT),
            'expired_at' => $attempt->expiredAt()?->format(self::DT),
        ];
    }

    private function hydrate(mixed $row): ?CheckoutAttempt
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);
        $abandonedAt = Row::nullableStr($row['abandoned_at'] ?? null);
        $expiredAt = Row::nullableStr($row['expired_at'] ?? null);

        return CheckoutAttempt::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::nullableStr($row['client_user_ref'] ?? null),
            Row::str($row['attempt_reference'] ?? ''),
            Row::int($row['package_id'] ?? null),
            Row::str($row['country'] ?? ''),
            Row::str($row['currency_code'] ?? ''),
            PurchaseType::tryFrom(Row::str($row['purchase_type'] ?? '')),
            PaymentMethod::tryFrom(Row::str($row['payment_method'] ?? '')),
            SubscriptionInterval::tryFrom(Row::str($row['subscription_interval'] ?? '')),
            CheckoutAttemptStatus::from(Row::str($row['status'] ?? 'started')),
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
            $abandonedAt !== null ? new DateTimeImmutable($abandonedAt) : null,
            $expiredAt !== null ? new DateTimeImmutable($expiredAt) : null,
        );
    }
}
