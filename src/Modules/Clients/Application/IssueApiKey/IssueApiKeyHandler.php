<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\IssueApiKey;

use Gomrok\Modules\Clients\Domain\ApiKeyGenerator;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Domain\Events\ApiKeyIssued;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Issues an additional API key for an existing, active client.
 */
final readonly class IssueApiKeyHandler
{
    public function __construct(
        private ClientRepository $clients,
        private ClientApiKeyRepository $apiKeys,
        private ApiKeyGenerator $generator,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(IssueApiKeyCommand $command): Result
    {
        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }

        if (!$client->isActive()) {
            return Result::err(DomainError::forbidden('client.disabled', 'Cannot issue an API key for a disabled client.'));
        }

        $now = $this->clock->now();
        $generated = $this->generator->generate($command->clientId, $command->prefix, $command->label, $now, $command->expiresAt);

        $clientId = $command->clientId;
        $keyId = $generated->apiKey->keyId();
        $prefix = $command->prefix->value;

        $this->transactions->run(function () use ($generated, $clientId, $keyId, $prefix): void {
            $this->apiKeys->save($generated->apiKey);

            $keyDbId = $generated->apiKey->id();
            \assert($keyDbId !== null);

            $this->audit->record(
                AuditEntry::forSystem('client.api_key_issued', $clientId)
                    ->withTarget('client_api_key', $keyDbId)
                    ->withChange(null, [
                        'client_id' => $clientId,
                        'key_id' => $keyId,
                        'prefix' => $prefix,
                        'last_four' => $generated->apiKey->lastFour(),
                        'label' => $generated->apiKey->label(),
                    ]),
            );
        });

        return Result::ok(new IssueApiKeyResult(
            $keyId,
            $generated->plaintextToken,
            new ApiKeyIssued($clientId, $keyId, $prefix, $now),
        ));
    }
}
