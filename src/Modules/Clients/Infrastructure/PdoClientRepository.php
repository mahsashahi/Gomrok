<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientEndpoint;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Domain\ClientSlug;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Shared\Domain\CountryCode;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see ClientRepository}. `save()` inserts or updates the `clients` row
 * and rewrites the client's `client_endpoints` (delete-all + reinsert — at most
 * a handful of rows). Callers wrap `save()` in a transaction.
 */
final readonly class PdoClientRepository implements ClientRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(Client $client): void
    {
        if ($client->id() === null) {
            $this->insert($client);
        } else {
            $this->update($client);
        }

        $id = $client->id();
        \assert($id !== null);
        $this->syncEndpoints($id, $client->endpoints());
    }

    public function findById(int $id): ?Client
    {
        $statement = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id');
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    public function findBySlug(string $slug): ?Client
    {
        $statement = $this->pdo->prepare('SELECT * FROM clients WHERE slug = :slug');
        $statement->execute(['slug' => $slug]);

        return $this->hydrate($statement->fetch());
    }

    public function existsWithSlug(string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM clients WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);

        return $statement->fetchColumn() !== false;
    }

    private function insert(Client $client): void
    {
        $now = $client->createdAt()->format(self::DT);

        $statement = $this->pdo->prepare(
            'INSERT INTO clients
                (slug, name, status, default_currency, default_country, timezone,
                 notification_signing_secret, created_at, updated_at)
             VALUES
                (:slug, :name, :status, :default_currency, :default_country, :timezone,
                 :secret, :created_at, :updated_at)',
        );
        $statement->execute([
            'slug' => (string) $client->slug(),
            'name' => $client->name(),
            'status' => $client->status()->value,
            'default_currency' => $client->defaultCurrency()->code(),
            'default_country' => $client->defaultCountry()?->value,
            'timezone' => $client->timezone(),
            'secret' => $client->notificationSigningSecret(),
            'created_at' => $now,
            'updated_at' => $client->updatedAt()?->format(self::DT),
        ]);

        $client->assignId((int) $this->pdo->lastInsertId());
    }

    private function update(Client $client): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE clients SET
                name = :name, status = :status, default_currency = :default_currency,
                default_country = :default_country, timezone = :timezone,
                notification_signing_secret = :secret,
                disabled_at = :disabled_at, disabled_by = :disabled_by, disabled_reason = :disabled_reason,
                updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'id' => $client->id(),
            'name' => $client->name(),
            'status' => $client->status()->value,
            'default_currency' => $client->defaultCurrency()->code(),
            'default_country' => $client->defaultCountry()?->value,
            'timezone' => $client->timezone(),
            'secret' => $client->notificationSigningSecret(),
            'disabled_at' => $client->disabledAt()?->format(self::DT),
            'disabled_by' => $client->disabledBy(),
            'disabled_reason' => $client->disabledReason(),
            'updated_at' => $client->updatedAt()?->format(self::DT),
        ]);
    }

    /**
     * @param list<ClientEndpoint> $endpoints
     */
    private function syncEndpoints(int $clientId, array $endpoints): void
    {
        $this->pdo->prepare('DELETE FROM client_endpoints WHERE client_id = :id')
            ->execute(['id' => $clientId]);

        if ($endpoints === []) {
            return;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO client_endpoints (client_id, purpose, url, is_active, created_at, updated_at)
             VALUES (:client_id, :purpose, :url, :is_active, :created_at, :updated_at)',
        );
        $now = (new DateTimeImmutable())->format(self::DT);

        foreach ($endpoints as $endpoint) {
            $insert->execute([
                'client_id' => $clientId,
                'purpose' => $endpoint->purpose()->value,
                'url' => $endpoint->url(),
                'is_active' => $endpoint->isActive() ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function hydrate(mixed $row): ?Client
    {
        if (!\is_array($row)) {
            return null;
        }

        $id = Row::int($row['id'] ?? null);
        $country = Row::nullableStr($row['default_country'] ?? null);
        $updatedAt = Row::nullableStr($row['updated_at'] ?? null);
        $disabledAt = Row::nullableStr($row['disabled_at'] ?? null);

        return Client::fromStorage(
            $id,
            ClientSlug::of(Row::str($row['slug'] ?? '')),
            Row::str($row['name'] ?? ''),
            ClientStatus::from(Row::str($row['status'] ?? 'active')),
            Currency::of(Row::str($row['default_currency'] ?? 'USD')),
            $country !== null ? CountryCode::of($country) : null,
            Row::str($row['timezone'] ?? 'UTC'),
            Row::str($row['notification_signing_secret'] ?? ''),
            $disabledAt !== null ? new DateTimeImmutable($disabledAt) : null,
            Row::nullableInt($row['disabled_by'] ?? null),
            Row::nullableStr($row['disabled_reason'] ?? null),
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
            $updatedAt !== null ? new DateTimeImmutable($updatedAt) : null,
            $this->loadEndpoints($id),
        );
    }

    /**
     * @return list<ClientEndpoint>
     */
    private function loadEndpoints(int $clientId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, purpose, url, is_active FROM client_endpoints WHERE client_id = :id ORDER BY purpose',
        );
        $statement->execute(['id' => $clientId]);

        $endpoints = [];
        while (($row = $statement->fetch()) !== false) {
            if (!\is_array($row)) {
                continue;
            }
            $endpoints[] = ClientEndpoint::fromStorage(
                Row::int($row['id'] ?? null),
                EndpointPurpose::from(Row::str($row['purpose'] ?? '')),
                Row::str($row['url'] ?? ''),
                Row::bool($row['is_active'] ?? null),
            );
        }

        return $endpoints;
    }
}
