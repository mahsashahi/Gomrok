<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\EnableClient;

use Gomrok\Modules\Clients\Application\ClientAuditSnapshot;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\Events\ClientEnabled;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Re-activates a disabled client. Idempotent for an already-active client.
 */
final readonly class EnableClientHandler
{
    public function __construct(
        private ClientRepository $clients,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(EnableClientCommand $command): Result
    {
        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }

        $now = $this->clock->now();
        $event = new ClientEnabled($command->clientId, (string) $client->slug(), $now);

        if ($client->status() === ClientStatus::Active) {
            return Result::ok($event);
        }

        $before = ClientAuditSnapshot::of($client);
        $client->enable($now);

        $this->transactions->run(function () use ($client, $before, $command): void {
            $this->clients->save($client);
            $this->audit->record(
                AuditEntry::forSystem('client.enabled', $command->clientId)
                    ->withTarget('client', $command->clientId)
                    ->withChange($before, ClientAuditSnapshot::of($client)),
            );
        });

        return Result::ok($event);
    }
}
