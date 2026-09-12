<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\LinkProviderCustomer;

use Gomrok\Modules\Payments\Domain\ProviderCustomer;
use Gomrok\Modules\Payments\Domain\ProviderCustomerRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Records a durable customer identity on one provider's side for later reuse
 * (e.g. recurring billing, Phase 21+). Idempotent by
 * `(provider_account_id, provider_customer_id)` — a repeat call for the same
 * pair returns the existing row unchanged when it belongs to the same client,
 * or `conflict` when it doesn't.
 */
final readonly class LinkProviderCustomerHandler
{
    public function __construct(
        private ProviderCustomerRepository $customers,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(LinkProviderCustomerCommand $command): Result
    {
        $existing = $this->customers->findByProviderCustomerId($command->providerAccountId, $command->providerCustomerId);
        if ($existing !== null) {
            if ($existing->clientId() !== $command->clientId) {
                return Result::err(DomainError::conflict(
                    'provider_customer.already_linked_to_another_client',
                    'This provider customer id is already linked to a different client.',
                ));
            }

            $id = $existing->id();
            \assert($id !== null);

            return Result::ok(new LinkProviderCustomerResult($id));
        }

        $customer = ProviderCustomer::link($command->clientId, $command->providerAccountId, $command->clientUserRef, $command->providerCustomerId, $this->clock->now());

        $this->transactions->run(function () use ($customer, $command): void {
            $this->customers->save($customer);

            $id = $customer->id();
            \assert($id !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'provider_customer.linked')
                : AuditEntry::forSystem('provider_customer.linked', $command->clientId);
            $this->audit->record($entry->withTarget('provider_customer', $id)->withChange(null, [
                'client_id' => $customer->clientId(),
                'provider_account_id' => $customer->providerAccountId(),
                'client_user_ref' => $customer->clientUserRef(),
                'provider_customer_id' => $customer->providerCustomerId(),
            ]));
        });

        $id = $customer->id();
        \assert($id !== null);

        return Result::ok(new LinkProviderCustomerResult($id));
    }
}
