<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Infrastructure;

use Gomrok\Modules\Clients\Application\ClientNotificationSecret;
use PDO;

final readonly class PdoClientNotificationSecret implements ClientNotificationSecret
{
    public function __construct(private PDO $pdo)
    {
    }

    public function secretFor(int $clientId): ?string
    {
        $statement = $this->pdo->prepare('SELECT notification_signing_secret FROM clients WHERE id = :id');
        $statement->execute(['id' => $clientId]);
        $secret = $statement->fetchColumn();

        return \is_string($secret) ? $secret : null;
    }
}
