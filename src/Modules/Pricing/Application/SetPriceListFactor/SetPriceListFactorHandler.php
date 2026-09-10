<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceListFactor;

use Gomrok\Modules\Pricing\Application\PriceListAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Changes a non-control price list's multiplier. The control list's factor is
 * locked at 1.0000.
 */
final readonly class SetPriceListFactorHandler
{
    public function __construct(
        private PriceListRepository $lists,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetPriceListFactorCommand $command): Result
    {
        $list = $this->lists->findById($command->priceListId);
        if ($list === null || $list->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('price_list.not_found', "Price list {$command->priceListId} was not found for this client."));
        }

        $before = PriceListAuditSnapshot::list($list);
        $now = $this->clock->now();
        $error = $list->changeFactor($command->factor, $now);
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($list, $before, $command): void {
            $this->lists->save($list);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'price_list.factor_changed')
                : AuditEntry::forSystem('price_list.factor_changed', $command->clientId);

            $this->audit->record(
                $entry->withTarget('price_list', $command->priceListId)
                    ->withChange($before, PriceListAuditSnapshot::list($list))
                    ->withContext(['pricing_group_id' => $list->pricingGroupId()]),
            );
        });

        return Result::ok(null);
    }
}
