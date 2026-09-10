<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use Gomrok\Modules\Providers\Application\ProviderAccountCredentials;
use Gomrok\Shared\Application\SecretCipher;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * The decrypt path for provider secrets — used only by provider adapters.
 */
final readonly class PdoProviderAccountCredentials implements ProviderAccountCredentials
{
    public function __construct(
        private PDO $pdo,
        private SecretCipher $cipher,
    ) {
    }

    public function secretFor(int $accountId): ?string
    {
        $statement = $this->pdo->prepare('SELECT secret_ciphertext FROM provider_accounts WHERE id = :id');
        $statement->execute(['id' => $accountId]);
        $ciphertext = $statement->fetchColumn();

        return $ciphertext === false ? null : $this->cipher->decrypt(Row::str($ciphertext));
    }

    public function endpointSigningSecret(int $accountId, string $endpointKind): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT signing_secret_ciphertext FROM provider_account_endpoints
              WHERE provider_account_id = :id AND kind = :kind AND is_active = 1
              ORDER BY id DESC LIMIT 1',
        );
        $statement->execute(['id' => $accountId, 'kind' => $endpointKind]);
        $ciphertext = $statement->fetchColumn();

        if ($ciphertext === false || $ciphertext === null) {
            return null;
        }

        return $this->cipher->decrypt(Row::str($ciphertext));
    }
}
