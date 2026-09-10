<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\SetClientEndpoint;

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
 * Registers/replaces a client's callback URL for one {@see EndpointPurpose}.
 * Requires an absolute `https://` URL.
 */
final readonly class SetClientEndpointHandler
{
    public function __construct(
        private ClientRepository $clients,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetClientEndpointCommand $command): Result
    {
        try {
            $purpose = EndpointPurpose::from($command->purpose);
        } catch (ValueError) {
            return Result::err(DomainError::validation('client.unknown_endpoint_purpose', "Unknown endpoint purpose '{$command->purpose}'.", ['purpose' => $command->purpose]));
        }

        $url = trim($command->url);
        if (!$this->isHttpsUrl($url)) {
            return Result::err(DomainError::validation('client.invalid_endpoint_url', 'A callback URL must be an absolute https:// URL.', ['url' => $command->url]));
        }

        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }

        $before = ClientAuditSnapshot::of($client);
        $now = $this->clock->now();
        $client->setEndpoint($purpose, $url, $now);

        $clientId = $command->clientId;
        $this->transactions->run(function () use ($client, $before, $clientId, $purpose): void {
            $this->clients->save($client);
            $this->audit->record(
                AuditEntry::forSystem('client.endpoint_set', $clientId)
                    ->withTarget('client', $clientId)
                    ->withChange($before, ClientAuditSnapshot::of($client))
                    ->withContext(['purpose' => $purpose->value]),
            );
        });

        return Result::ok(new ClientUpdated($clientId, (string) $client->slug(), ['endpoint:' . $purpose->value], $now));
    }

    private function isHttpsUrl(string $url): bool
    {
        if (filter_var($url, \FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return str_starts_with(strtolower($url), 'https://');
    }
}
