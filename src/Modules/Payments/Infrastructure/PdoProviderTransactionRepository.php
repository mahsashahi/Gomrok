<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\ProviderTransaction;
use Gomrok\Modules\Payments\Domain\ProviderTransactionRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoProviderTransactionRepository implements ProviderTransactionRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(ProviderTransaction $transaction): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO provider_transactions
                (payment_attempt_id, kind, request_payload, response_payload, provider_status_raw, created_at)
             VALUES (:attempt, :kind, :request, :response, :status_raw, :now)',
        );
        $statement->execute([
            'attempt' => $transaction->paymentAttemptId,
            'kind' => $transaction->kind,
            'request' => $this->encode($transaction->requestPayload),
            'response' => $this->encode($transaction->responsePayload),
            'status_raw' => $transaction->providerStatusRaw,
            'now' => $transaction->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function forAttempt(int $paymentAttemptId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_transactions WHERE payment_attempt_id = :a ORDER BY id ASC');
        $statement->execute(['a' => $paymentAttemptId]);

        $transactions = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $transactions[] = $this->hydrate($row);
            }
        }

        return $transactions;
    }

    /**
     * @param array<array-key, mixed>|null $payload
     */
    private function encode(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $json = json_encode($payload);

        return $json === false ? '{}' : $json;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): ProviderTransaction
    {
        $request = Row::nullableStr($row['request_payload'] ?? null);
        $response = Row::nullableStr($row['response_payload'] ?? null);

        $requestPayload = $request !== null ? json_decode($request, true) : null;
        $responsePayload = $response !== null ? json_decode($response, true) : null;

        return new ProviderTransaction(
            Row::int($row['id'] ?? null),
            Row::int($row['payment_attempt_id'] ?? null),
            Row::str($row['kind'] ?? ''),
            \is_array($requestPayload) ? $requestPayload : null,
            \is_array($responsePayload) ? $responsePayload : null,
            Row::str($row['provider_status_raw'] ?? ''),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
