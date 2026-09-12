<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoGatewayReferenceRepository implements GatewayReferenceRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function save(GatewayReference $reference): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO gateway_references (client_id, provider_account_id, reference_type, reference_value, payment_id, created_at)
             VALUES (:client_id, :provider_account_id, :reference_type, :reference_value, :payment_id, :now)',
        );
        $statement->execute([
            'client_id' => $reference->clientId,
            'provider_account_id' => $reference->providerAccountId,
            'reference_type' => $reference->referenceType->value,
            'reference_value' => $reference->referenceValue,
            'payment_id' => $reference->paymentId,
            'now' => $reference->createdAt->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByReference(int $providerAccountId, GatewayReferenceType $referenceType, string $referenceValue): ?GatewayReference
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM gateway_references WHERE provider_account_id = :a AND reference_type = :t AND reference_value = :v',
        );
        $statement->execute(['a' => $providerAccountId, 't' => $referenceType->value, 'v' => $referenceValue]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    public function forPayment(int $paymentId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM gateway_references WHERE payment_id = :p ORDER BY id ASC');
        $statement->execute(['p' => $paymentId]);

        $references = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $references[] = $this->hydrate($row);
            }
        }

        return $references;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): GatewayReference
    {
        return new GatewayReference(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            GatewayReferenceType::from(Row::str($row['reference_type'] ?? 'other')),
            Row::str($row['reference_value'] ?? ''),
            Row::nullableInt($row['payment_id'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
