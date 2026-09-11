<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoProviderRoutingDecisionSnapshotRepository implements ProviderRoutingDecisionSnapshotRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(ProviderRoutingDecisionSnapshot $snapshot): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO provider_routing_decision_snapshots
                (checkout_attempt_id, client_id, provider_account_id, payment_method, purchase_type, payload, created_at)
             VALUES (:attempt, :client, :account, :method, :purchase_type, :payload, :now)',
        );
        $statement->execute([
            'attempt' => $snapshot->checkoutAttemptId,
            'client' => $snapshot->clientId,
            'account' => $snapshot->providerAccountId,
            'method' => $snapshot->paymentMethod,
            'purchase_type' => $snapshot->purchaseType,
            'payload' => $this->encode($snapshot->payload),
            'now' => $snapshot->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?ProviderRoutingDecisionSnapshot
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_routing_decision_snapshots WHERE checkout_attempt_id = :id');
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
    private function hydrate(array $row): ProviderRoutingDecisionSnapshot
    {
        $payload = json_decode(Row::str($row['payload'] ?? '{}'), true);

        return new ProviderRoutingDecisionSnapshot(
            Row::int($row['id'] ?? null),
            Row::int($row['checkout_attempt_id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::nullableStr($row['payment_method'] ?? null),
            Row::str($row['purchase_type'] ?? ''),
            \is_array($payload) ? $payload : [],
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
