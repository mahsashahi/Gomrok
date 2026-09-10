<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\CreatePriceList;

use Gomrok\Modules\Pricing\Application\PriceListAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Creates a non-control price list: validates the group belongs to the client,
 * the name is free within the group, and the factor is a positive decimal.
 */
final readonly class CreatePriceListHandler
{
    public function __construct(
        private PriceListRepository $lists,
        private PricingGroupRepository $groups,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreatePriceListCommand $command): Result
    {
        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('price_list.name_required', 'A name is required.'));
        }
        if (strcasecmp($name, PriceList::CONTROL_NAME) === 0) {
            return Result::err(DomainError::validation('price_list.reserved_name', "'" . PriceList::CONTROL_NAME . "' is reserved for the control list."));
        }

        $error = PriceList::validateFactor($command->factor);
        if ($error !== null) {
            return Result::err($error);
        }

        $group = $this->groups->findById($command->pricingGroupId);
        if ($group === null || $group->clientId() !== $command->clientId) {
            return Result::err(DomainError::validation('price_list.group_not_owned', "Pricing group {$command->pricingGroupId} does not belong to this client.", ['pricing_group_id' => $command->pricingGroupId]));
        }

        if ($this->lists->existsForGroupWithName($command->pricingGroupId, $name)) {
            return Result::err(DomainError::conflict('price_list.name_taken', "Pricing group '{$group->slug()->value}' already has a price list named '{$name}'.", ['name' => $name]));
        }

        $now = $this->clock->now();
        $list = PriceList::experiment($command->clientId, $command->pricingGroupId, $name, $command->factor, $now);

        $this->transactions->run(function () use ($list, $command): void {
            $this->lists->save($list);
            $listId = $list->id();
            \assert($listId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'price_list.created')
                : AuditEntry::forSystem('price_list.created', $command->clientId);

            $this->audit->record(
                $entry->withTarget('price_list', $listId)
                    ->withChange(null, PriceListAuditSnapshot::list($list))
                    ->withContext(['pricing_group_id' => $command->pricingGroupId]),
            );
        });

        $listId = $list->id();
        \assert($listId !== null);

        return Result::ok(new CreatePriceListResult($listId));
    }
}
