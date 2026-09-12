<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentAttemptRepository;
use Gomrok\Modules\Payments\Domain\PaymentAttemptStatus;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPaymentAttemptRepository implements PaymentAttemptRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(PaymentAttempt $attempt): void
    {
        if ($attempt->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO payment_attempts
                    (payment_id, provider_account_id, attempt_number, status, payment_method,
                     error_code, error_message, created_at, updated_at)
                 VALUES
                    (:payment_id, :provider_account_id, :attempt_number, :status, :payment_method,
                     :error_code, :error_message, :created_at, :updated_at)',
            );
            $statement->execute($this->params($attempt) + [
                'created_at' => $attempt->createdAt()->format(self::DT),
                'updated_at' => $attempt->updatedAt()?->format(self::DT),
            ]);
            $attempt->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE payment_attempts SET status = :status, error_code = :error_code, error_message = :error_message, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $attempt->id(),
            'status' => $attempt->status()->value,
            'error_code' => $attempt->errorCode(),
            'error_message' => $attempt->errorMessage(),
            'updated_at' => $attempt->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
        ]);
    }

    public function findById(int $id): ?PaymentAttempt
    {
        $statement = $this->pdo->prepare('SELECT * FROM payment_attempts WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findLatestForPayment(int $paymentId): ?PaymentAttempt
    {
        $statement = $this->pdo->prepare('SELECT * FROM payment_attempts WHERE payment_id = :p ORDER BY attempt_number DESC LIMIT 1');
        $statement->execute(['p' => $paymentId]);

        return $this->hydrate($statement->fetch());
    }

    public function countForPayment(int $paymentId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM payment_attempts WHERE payment_id = :p');
        $statement->execute(['p' => $paymentId]);

        return (int) $statement->fetchColumn();
    }

    public function forPayment(int $paymentId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM payment_attempts WHERE payment_id = :p ORDER BY attempt_number ASC');
        $statement->execute(['p' => $paymentId]);

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
    private function params(PaymentAttempt $attempt): array
    {
        return [
            'payment_id' => $attempt->paymentId(),
            'provider_account_id' => $attempt->providerAccountId(),
            'attempt_number' => $attempt->attemptNumber(),
            'status' => $attempt->status()->value,
            'payment_method' => $attempt->paymentMethod()?->value,
            'error_code' => $attempt->errorCode(),
            'error_message' => $attempt->errorMessage(),
        ];
    }

    private function hydrate(mixed $row): ?PaymentAttempt
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return PaymentAttempt::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['payment_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::int($row['attempt_number'] ?? null),
            PaymentAttemptStatus::from(Row::str($row['status'] ?? 'started')),
            PaymentMethod::tryFrom(Row::str($row['payment_method'] ?? '')),
            Row::nullableStr($row['error_code'] ?? null),
            Row::nullableStr($row['error_message'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
