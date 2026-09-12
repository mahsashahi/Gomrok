<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Payments\Domain\ProviderCustomer;
use Gomrok\Modules\Payments\Domain\ProviderCustomerRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoProviderCustomerRepository implements ProviderCustomerRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ProviderCustomer $customer): void
    {
        if ($customer->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO provider_customers (client_id, provider_account_id, client_user_ref, provider_customer_id, created_at, updated_at)
                 VALUES (:client_id, :provider_account_id, :client_user_ref, :provider_customer_id, :created_at, :updated_at)',
            );
            $statement->execute([
                'client_id' => $customer->clientId(),
                'provider_account_id' => $customer->providerAccountId(),
                'client_user_ref' => $customer->clientUserRef(),
                'provider_customer_id' => $customer->providerCustomerId(),
                'created_at' => $customer->createdAt()->format(self::DT),
                'updated_at' => $customer->updatedAt()?->format(self::DT),
            ]);
            $customer->assignId((int) $this->pdo->lastInsertId());

            return;
        }

        $statement = $this->pdo->prepare('UPDATE provider_customers SET updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'id' => $customer->id(),
            'updated_at' => $customer->updatedAt()?->format(self::DT) ?? gmdate(self::DT),
        ]);
    }

    public function findByProviderCustomerId(int $providerAccountId, string $providerCustomerId): ?ProviderCustomer
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_customers WHERE provider_account_id = :a AND provider_customer_id = :c');
        $statement->execute(['a' => $providerAccountId, 'c' => $providerCustomerId]);

        return $this->hydrate($statement->fetch());
    }

    public function find(int $providerAccountId, string $clientUserRef): ?ProviderCustomer
    {
        $statement = $this->pdo->prepare('SELECT * FROM provider_customers WHERE provider_account_id = :a AND client_user_ref = :u');
        $statement->execute(['a' => $providerAccountId, 'u' => $clientUserRef]);

        return $this->hydrate($statement->fetch());
    }

    private function hydrate(mixed $row): ?ProviderCustomer
    {
        if (!\is_array($row)) {
            return null;
        }

        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);

        return ProviderCustomer::fromStorage(
            Row::int($row['id'] ?? null),
            Row::int($row['client_id'] ?? null),
            Row::int($row['provider_account_id'] ?? null),
            Row::str($row['client_user_ref'] ?? ''),
            Row::str($row['provider_customer_id'] ?? ''),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
        );
    }
}
