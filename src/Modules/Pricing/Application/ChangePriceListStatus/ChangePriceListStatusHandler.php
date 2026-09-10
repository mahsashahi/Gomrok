<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\ChangePriceListStatus;

use Gomrok\Modules\Pricing\Application\PriceListAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Enable / disable an A/B price list (soft, reversible). Idempotent. The control
 * list can never be disabled — a group always needs a baseline bucket.
 */
final readonly class ChangePriceListStatusHandler
{
    public function __construct(
        private PriceListRepository $lists,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function enable(int $priceListId, int $clientId, ?int $actorId = null): Result
    {
        return $this->apply($priceListId, $clientId, true, $actorId);
    }

    public function disable(int $priceListId, int $clientId, ?int $actorId = null): Result
    {
        return $this->apply($priceListId, $clientId, false, $actorId);
    }

    private function apply(int $priceListId, int $clientId, bool $enable, ?int $actorId): Result
    {
        $list = $this->lists->findById($priceListId);
        if ($list === null || $list->clientId() !== $clientId) {
            return Result::err(DomainError::notFound('price_list.not_found', "Price list {$priceListId} was not found for this client."));
        }

        if ($list->isEnabled() === $enable) {
            return Result::ok(null);
        }

        $before = PriceListAuditSnapshot::list($list);
        $now = $this->clock->now();

        if ($enable) {
            $list->enable($now);
        } else {
            $error = $list->disable($now);
            if ($error !== null) {
                return Result::err($error);
            }
        }

        $action = $enable ? 'price_list.enabled' : 'price_list.disabled';
        $this->transactions->run(function () use ($list, $before, $clientId, $priceListId, $action, $actorId): void {
            $this->lists->save($list);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, $action)
                : AuditEntry::forSystem($action, $clientId);

            $this->audit->record(
                $entry->withTarget('price_list', $priceListId)
                    ->withChange($before, PriceListAuditSnapshot::list($list))
                    ->withContext(['pricing_group_id' => $list->pricingGroupId()]),
            );
        });

        return Result::ok(null);
    }
}
