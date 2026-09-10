<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\RemoveClientEndpoint;

use Gomrok\Modules\Clients\Application\ClientAuditSnapshot;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Clients\Domain\Events\ClientUpdated;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;
use ValueError;

/**
 * Removes a client's callback URL for one purpose. Idempotent — removing an
 * absent endpoint succeeds.
 */
final readonly class RemoveClientEndpointHandler
{
    public function __construct(
        private ClientRepository $clients,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(RemoveClientEndpointCommand $command): Result
    {
        try {
            $purpose = EndpointPurpose::from($command->purpose);
        } catch (ValueError) {
            return Result::err(DomainError::validation('client.unknown_endpoint_purpose', "Unknown endpoint purpose '{$command->purpose}'.", ['purpose' => $command->purpose]));
        }

        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }

        $now = $this->clock->now();
        $slug = (string) $client->slug();

        $hasEndpoint = false;
        foreach ($client->endpoints() as $endpoint) {
            if ($endpoint->purpose() === $purpose) {
                $hasEndpoint = true;
                break;
            }
        }

        if (!$hasEndpoint) {
            return Result::ok(new ClientUpdated($command->clientId, $slug, [], $now));
        }

        $before = ClientAuditSnapshot::of($client);
        $client->removeEndpoint($purpose, $now);

        $clientId = $command->clientId;
        $this->transactions->run(function () use ($client, $before, $clientId, $purpose): void {
            $this->clients->save($client);
            $this->audit->record(
                AuditEntry::forSystem('client.endpoint_removed', $clientId)
                    ->withTarget('client', $clientId)
                    ->withChange($before, ClientAuditSnapshot::of($client))
                    ->withContext(['purpose' => $purpose->value]),
            );
        });

        return Result::ok(new ClientUpdated($clientId, $slug, ['endpoint:' . $purpose->value], $now));
    }
}
