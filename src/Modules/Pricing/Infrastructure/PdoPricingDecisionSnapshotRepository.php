<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshotRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoPricingDecisionSnapshotRepository implements PricingDecisionSnapshotRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(PricingDecisionSnapshot $snapshot): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pricing_decision_snapshots
                (checkout_attempt_id, client_id, package_id, currency_code, amount_minor, source, payload, created_at)
             VALUES (:attempt, :client, :package, :currency, :amount, :source, :payload, :now)',
        );
        $statement->execute([
            'attempt' => $snapshot->checkoutAttemptId,
            'client' => $snapshot->clientId,
            'package' => $snapshot->packageId,
            'currency' => $snapshot->currencyCode,
            'amount' => $snapshot->amountMinor,
            'source' => $snapshot->source,
            'payload' => $this->encode($snapshot->payload),
            'now' => $snapshot->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?PricingDecisionSnapshot
    {
        $statement = $this->pdo->prepare('SELECT * FROM pricing_decision_snapshots WHERE checkout_attempt_id = :id');
        $statement->execute(['id' => $checkoutAttemptId]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function encode(array $payload): string
    {
        $json = json_encode($payload);

        return $json === false ? '{}' : $json;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): PricingDecisionSnapshot
    {
        $payload = json_decode(Row::str($row['payload'] ?? '{}'), true);

        return new PricingDecisionSnapshot(
            Row::int($row['id'] ?? null),
            Row::int($row['checkout_attempt_id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['package_id'] ?? null),
            Row::str($row['currency_code'] ?? ''),
            Row::int($row['amount_minor'] ?? null),
            Row::str($row['source'] ?? ''),
            \is_array($payload) ? $payload : [],
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
