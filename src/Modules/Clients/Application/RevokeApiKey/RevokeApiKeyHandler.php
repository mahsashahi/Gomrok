<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\RevokeApiKey;

use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;
use Gomrok\Modules\Clients\Domain\Events\ApiKeyRevoked;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Revokes an API key by its `key_id`. Idempotent — revoking an already-revoked
 * key succeeds without a second audit row.
 */
final readonly class RevokeApiKeyHandler
{
    public function __construct(
        private ClientApiKeyRepository $apiKeys,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(RevokeApiKeyCommand $command): Result
    {
        $apiKey = $this->apiKeys->findByKeyId($command->keyId);
        if ($apiKey === null) {
            return Result::err(DomainError::notFound('client.api_key_not_found', "API key '{$command->keyId}' was not found."));
        }

        $now = $this->clock->now();
        $event = new ApiKeyRevoked($apiKey->clientId(), $apiKey->keyId(), $command->revokedBy, $now);

        if ($apiKey->status() === ApiKeyStatus::Revoked) {
            return Result::ok($event);
        }

        $keyDbId = $apiKey->id();
        \assert($keyDbId !== null);
        $clientId = $apiKey->clientId();

        $apiKey->revoke($command->revokedBy, $now);

        $this->transactions->run(function () use ($apiKey, $keyDbId, $clientId, $command): void {
            $this->apiKeys->save($apiKey);
            $this->audit->record(
                AuditEntry::forSystem('client.api_key_revoked', $clientId)
                    ->withTarget('client_api_key', $keyDbId)
                    ->withChange(
                        ['key_id' => $apiKey->keyId(), 'status' => ApiKeyStatus::Active->value],
                        ['key_id' => $apiKey->keyId(), 'status' => ApiKeyStatus::Revoked->value],
                    )
                    ->withContext(['revoked_by' => $command->revokedBy]),
            );
        });

        return Result::ok($event);
    }
}
