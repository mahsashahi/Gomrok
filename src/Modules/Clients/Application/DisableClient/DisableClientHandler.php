<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\DisableClient;

use Gomrok\Modules\Clients\Application\ClientAuditSnapshot;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Modules\Clients\Domain\Events\ClientDisabled;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Soft-disables a client (reversible, keys untouched — Phase 6 Q4). Idempotent:
 * disabling an already-disabled client succeeds without a second audit row.
 */
final readonly class DisableClientHandler
{
    public function __construct(
        private ClientRepository $clients,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(DisableClientCommand $command): Result
    {
        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }

        $now = $this->clock->now();
        $event = new ClientDisabled($command->clientId, (string) $client->slug(), $command->reason, $now);

        if ($client->status() === ClientStatus::Disabled) {
            return Result::ok($event);
        }

        $before = ClientAuditSnapshot::of($client);
        $client->disable($command->disabledBy, $command->reason, $now);

        $this->transactions->run(function () use ($client, $before, $command): void {
            $this->clients->save($client);
            $this->audit->record(
                AuditEntry::forSystem('client.disabled', $command->clientId)
                    ->withTarget('client', $command->clientId)
                    ->withChange($before, ClientAuditSnapshot::of($client))
                    ->withContext(['reason' => $command->reason]),
            );
        });

        return Result::ok($event);
    }
}
