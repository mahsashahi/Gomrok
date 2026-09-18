<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Infrastructure;

use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverride;
use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverrideRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoProviderAccountNotificationOverrideRepository implements ProviderAccountNotificationOverrideRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(ProviderAccountNotificationOverride $override): void
    {
        $now = gmdate(self::DT);

        if ($override->id() === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO provider_account_notification_overrides
                    (provider_account_id, purpose, url, is_active, created_at, updated_at)
                 VALUES (:provider_account_id, :purpose, :url, :is_active, :created_at, :updated_at)
                 ON DUPLICATE KEY UPDATE url = VALUES(url), is_active = VALUES(is_active), updated_at = VALUES(updated_at)',
            );
            $statement->execute([
                'provider_account_id' => $override->providerAccountId(),
                'purpose' => $override->purpose(),
                'url' => $override->url(),
                'is_active' => $override->isActive() ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $id = $this->pdo->lastInsertId();
            if ((int) $id > 0) {
                $override->assignId((int) $id);
            }

            return;
        }

        $statement = $this->pdo->prepare(
            'UPDATE provider_account_notification_overrides SET url = :url, is_active = :is_active, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute([
            'id' => $override->id(),
            'url' => $override->url(),
            'is_active' => $override->isActive() ? 1 : 0,
            'updated_at' => $now,
        ]);
    }

    public function remove(int $providerAccountId, string $purpose): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM provider_account_notification_overrides WHERE provider_account_id = :account AND purpose = :purpose',
        );
        $statement->execute(['account' => $providerAccountId, 'purpose' => $purpose]);
    }

    public function findActiveUrl(int $providerAccountId, string $purpose): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT url FROM provider_account_notification_overrides
              WHERE provider_account_id = :account AND purpose = :purpose AND is_active = 1',
        );
        $statement->execute(['account' => $providerAccountId, 'purpose' => $purpose]);
        $url = $statement->fetchColumn();

        return \is_string($url) ? $url : null;
    }

    public function forAccount(int $providerAccountId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM provider_account_notification_overrides WHERE provider_account_id = :account ORDER BY purpose',
        );
        $statement->execute(['account' => $providerAccountId]);

        $out = [];
        while (($row = $statement->fetch()) !== false) {
            \assert(\is_array($row));
            $out[] = ProviderAccountNotificationOverride::fromStorage(
                Row::int($row['id'] ?? null),
                Row::int($row['provider_account_id'] ?? null),
                Row::str($row['purpose'] ?? ''),
                Row::str($row['url'] ?? ''),
                Row::bool($row['is_active'] ?? null),
            );
        }

        return $out;
    }
}
